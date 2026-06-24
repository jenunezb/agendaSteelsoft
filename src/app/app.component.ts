import { CommonModule } from '@angular/common';
import { Component, OnInit, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { catchError, forkJoin, of } from 'rxjs';
import { AgendaApiService } from './agenda-api.service';
import {
  Activity,
  AuthSession,
  AuthUser,
  CalendarDay,
  FinancialEntry,
  GeneralPending,
  PublicProfile,
  WeekGroup
} from './app.models';

type ViewMode = 'month' | 'week';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './app.component.html',
  styleUrl: './app.component.css'
})
export class AppComponent implements OnInit {
  private readonly agendaApi = inject(AgendaApiService);
  protected readonly reminderOptions = [
    { value: null, label: 'Sin notificacion' },
    { value: 60, label: '1 hora antes' },
    { value: 30, label: '30 minutos antes' },
    { value: 15, label: '15 minutos antes' },
    { value: 5, label: '5 minutos antes' },
    { value: 2, label: '2 minutos antes' },
    { value: 1, label: '1 minuto antes' }
  ];
  protected readonly weekDayNames = ['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
  protected readonly fullWeekDayNames = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
  protected readonly monthNames = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre'
  ];
  protected readonly timeOptions = this.buildTimeOptions();
  protected readonly weeklyTimeOptions = this.buildWeeklyTimeOptions();
  protected readonly weeklyHourStart = 8;
  protected readonly weeklyHourEnd = 18;
  protected readonly weeklyRowHeight = 72;

  protected currentMonth = this.startOfMonth(new Date());
  protected selectedDate = this.toIsoDate(this.normalizeCalendarDate(new Date()));
  protected currentUser: AuthUser | null = null;
  protected publicProfileUser: PublicProfile['user'] = null;
  protected canRegister = false;
  protected authMode: 'login' | 'register' = 'login';
  protected authError = '';
  protected isAuthLoading = true;
  protected isSubmittingAuth = false;
  protected isPublicProfileMode = false;
  protected publicProfileSlug = '';
  protected publicProfileState: 'loading' | 'ready' | 'not-found' | 'disabled' = 'loading';
  protected isPrivacyModalOpen = false;
  protected isUpdatingProfileVisibility = false;
  protected isUpdatingNotificationSettings = false;
  protected notificationSettingsMessage = '';
  protected notificationSettingsError = '';
  protected loginForm = {
    username: '',
    password: ''
  };
  protected registerForm = {
    name: 'Cristian',
    username: 'cristian',
    password: ''
  };
  protected notificationSettingsForm = {
    whatsappNumber: '',
    whatsappNotificationsEnabled: false,
    telegramChatId: '',
    telegramNotificationsEnabled: false
  };
  protected financeFilters = {
    startDate: '',
    endDate: ''
  };
  protected isActivityPanelOpen = false;
  protected isPendingPanelOpen = false;
  protected isFinancePanelOpen = false;
  protected viewMode: ViewMode = 'month';
  protected editingActivityId: number | null = null;
  protected editingGeneralPendingId: number | null = null;
  protected editingFinancialEntryId: number | null = null;
  protected activities: Activity[] = [];
  protected generalPendings: GeneralPending[] = [];
  protected financialEntries: FinancialEntry[] = [];
  protected newActivity: Omit<Activity, 'id'> = this.buildEmptyActivity();
  protected newGeneralPending: Omit<GeneralPending, 'id'> = this.buildEmptyGeneralPending();
  protected newFinancialEntry: Omit<FinancialEntry, 'id'> = this.buildEmptyFinancialEntry();

  ngOnInit(): void {
    const publicSlug = this.getPublicSlugFromLocation();

    if (publicSlug) {
      this.publicProfileSlug = publicSlug;
      this.isPublicProfileMode = true;
      this.loadPublicProfile(publicSlug);
      return;
    }

    this.loadSession();
  }

  protected get assigneeOptions(): string[] {
    return this.currentUser ? [this.currentUser.name] : [];
  }

  protected get isAuthenticated(): boolean {
    return this.currentUser !== null;
  }

  protected get showPrivateWorkspace(): boolean {
    return this.isAuthenticated && !this.isPublicProfileMode;
  }

  protected get activeProfileName(): string {
    return this.publicProfileUser?.name ?? this.currentUser?.name ?? 'Agenda';
  }

  protected get currentPublicUrl(): string {
    return this.currentUser?.publicUrl ?? '';
  }

  protected setAuthMode(mode: 'login' | 'register'): void {
    this.authError = '';
    this.authMode = mode;
  }

  protected openPrivacyModal(): void {
    this.isPrivacyModalOpen = true;
  }

  protected closePrivacyModal(): void {
    this.isPrivacyModalOpen = false;
  }

  protected toggleProfileVisibility(): void {
    if (!this.currentUser) {
      return;
    }

    this.isUpdatingProfileVisibility = true;
    this.agendaApi.updateProfileVisibility(!this.currentUser.profilePublic).subscribe({
      next: (session) => {
        this.isUpdatingProfileVisibility = false;
        this.applySession(session);
      },
      error: (error) => {
        this.isUpdatingProfileVisibility = false;
        this.authError = error?.error?.message ?? 'No fue posible actualizar la visibilidad del perfil.';
      }
    });
  }

  protected saveNotificationSettings(): void {
    if (!this.currentUser) {
      return;
    }

    const whatsappNumber = this.notificationSettingsForm.whatsappNumber.trim();
    const whatsappNotificationsEnabled = this.notificationSettingsForm.whatsappNotificationsEnabled;
    const telegramChatId = this.notificationSettingsForm.telegramChatId.trim();
    const telegramNotificationsEnabled = this.notificationSettingsForm.telegramNotificationsEnabled;

    if (whatsappNotificationsEnabled && !whatsappNumber) {
      this.notificationSettingsError = 'Ingresa el numero de WhatsApp para activar notificaciones.';
      this.notificationSettingsMessage = '';
      return;
    }

    if (telegramNotificationsEnabled && !telegramChatId) {
      this.notificationSettingsError = 'Ingresa el chat ID de Telegram para activar notificaciones.';
      this.notificationSettingsMessage = '';
      return;
    }

    this.isUpdatingNotificationSettings = true;
    this.notificationSettingsError = '';
    this.notificationSettingsMessage = '';
    this.agendaApi
      .updateNotificationSettings(
        whatsappNumber,
        whatsappNotificationsEnabled,
        telegramChatId,
        telegramNotificationsEnabled
      )
      .subscribe({
        next: (session) => {
          this.isUpdatingNotificationSettings = false;
          this.applySession(session);
          this.notificationSettingsMessage = 'Canales de notificacion actualizados.';
        },
        error: (error) => {
          this.isUpdatingNotificationSettings = false;
          this.notificationSettingsError =
            error?.error?.message ?? 'No fue posible guardar la configuracion de notificaciones.';
        }
      });
  }

  protected login(): void {
    const username = this.loginForm.username.trim().toLowerCase();
    const password = this.loginForm.password;

    if (!username || !password) {
      this.authError = 'Ingresa tu usuario y tu contrasena.';
      return;
    }

    this.isSubmittingAuth = true;
    this.authError = '';
    this.agendaApi.login(username, password).subscribe({
      next: (session) => {
        this.isSubmittingAuth = false;
        this.loginForm.password = '';
        this.handleAuthenticatedSession(session);
      },
      error: (error) => {
        this.isSubmittingAuth = false;
        this.authError = error?.error?.message ?? 'No fue posible iniciar sesion.';
      }
    });
  }

  protected register(): void {
    const name = this.registerForm.name.trim();
    const username = this.registerForm.username.trim().toLowerCase();
    const password = this.registerForm.password;

    if (!name || !username || !password) {
      this.authError = 'Completa nombre, usuario y contrasena.';
      return;
    }

    this.isSubmittingAuth = true;
    this.authError = '';
    this.agendaApi.register(name, username, password).subscribe({
      next: (session) => {
        this.isSubmittingAuth = false;
        this.registerForm.password = '';
        this.handleAuthenticatedSession(session);
      },
      error: (error) => {
        this.isSubmittingAuth = false;
        this.authError = error?.error?.message ?? 'No fue posible crear el usuario.';
      }
    });
  }

  protected logout(): void {
    this.agendaApi.logout().subscribe({
      next: () => {
        this.currentUser = null;
        this.activities = [];
        this.generalPendings = [];
        this.financialEntries = [];
        this.authMode = 'login';
        this.authError = '';
        this.closeAllPanels();
        this.resetActivityForm();
        this.resetGeneralPendingForm();
        this.resetFinancialEntryForm();
      }
    });
  }

  protected setViewMode(mode: ViewMode): void {
    this.viewMode = mode;
  }

  protected previousPeriod(): void {
    if (this.viewMode === 'month') {
      this.currentMonth = new Date(
        this.currentMonth.getFullYear(),
        this.currentMonth.getMonth() - 1,
        1
      );
      return;
    }

    const previousWeek = this.addDays(this.selectedDateAsDate, -7);
    this.selectDate(this.toIsoDate(previousWeek));
  }

  protected nextPeriod(): void {
    if (this.viewMode === 'month') {
      this.currentMonth = new Date(
        this.currentMonth.getFullYear(),
        this.currentMonth.getMonth() + 1,
        1
      );
      return;
    }

    const nextWeek = this.addDays(this.selectedDateAsDate, 7);
    this.selectDate(this.toIsoDate(nextWeek));
  }

  protected selectDate(isoDate: string): void {
    const normalizedDate = this.normalizeCalendarDate(new Date(`${isoDate}T00:00:00`));
    const normalizedIsoDate = this.toIsoDate(normalizedDate);
    this.selectedDate = normalizedIsoDate;
    this.newActivity.date = normalizedIsoDate;
    this.currentMonth = this.startOfMonth(normalizedDate);
  }

  protected openActivityPanel(isoDate: string): void {
    this.selectDate(isoDate);
    this.isActivityPanelOpen = true;
  }

  protected closeActivityPanel(): void {
    this.isActivityPanelOpen = false;
    this.resetActivityForm();
  }

  protected openPendingPanel(): void {
    this.isPendingPanelOpen = true;
  }

  protected closePendingPanel(): void {
    this.isPendingPanelOpen = false;
    this.resetGeneralPendingForm();
  }

  protected openFinancePanel(): void {
    this.isFinancePanelOpen = true;
    this.loadFinancialEntries();
  }

  protected closeFinancePanel(): void {
    this.isFinancePanelOpen = false;
    this.resetFinancialEntryForm();
  }

  protected addActivity(): void {
    const title = this.newActivity.title.trim();
    const startTime = this.newActivity.startTime;
    const endTime = this.newActivity.endTime;
    const assignee = this.newActivity.assignee;
    const location = this.newActivity.location.trim();
    const description = this.newActivity.description.trim();

    if (!title || !this.newActivity.date || !startTime || !endTime || !assignee) {
      return;
    }

    if (this.compareTimes(startTime, endTime) >= 0) {
      return;
    }

    const activity: Activity = {
      id: this.editingActivityId ?? Date.now(),
      title,
      startTime,
      endTime,
      assignee,
      visibility: this.newActivity.visibility,
      completed: this.newActivity.completed,
      location,
      description,
      date: this.newActivity.date,
      reminderMinutes: this.newActivity.reminderMinutes
    };

    const request =
      this.editingActivityId === null
        ? this.agendaApi.createActivity(this.toActivityPayload(activity))
        : this.agendaApi.updateActivity(
            this.editingActivityId,
            this.toActivityPayload({ ...activity, id: this.editingActivityId })
          );

    request.subscribe((savedActivity) => {
      this.activities = (
        this.editingActivityId === null
          ? [...this.activities, savedActivity]
          : this.activities.map((existingActivity) =>
              existingActivity.id === this.editingActivityId ? savedActivity : existingActivity
            )
      ).sort((left, right) => this.compareActivities(left, right));
      this.selectDate(savedActivity.date);
      this.resetActivityForm();
    });
  }

  protected removeActivity(activityId: number): void {
    this.agendaApi.deleteActivity(activityId).subscribe(() => {
      this.activities = this.activities.filter((activity) => activity.id !== activityId);

      if (this.editingActivityId === activityId) {
        this.resetActivityForm();
      }
    });
  }

  protected toggleActivityCompleted(activityId: number): void {
    const targetActivity = this.activities.find((activity) => activity.id === activityId);

    if (!targetActivity) {
      return;
    }

    const updatedActivity = { ...targetActivity, completed: !targetActivity.completed };

    this.agendaApi
      .updateActivity(activityId, this.toActivityPayload(updatedActivity))
      .subscribe((savedActivity) => {
        this.activities = this.activities
          .map((activity) => (activity.id === activityId ? savedActivity : activity))
          .sort((left, right) => this.compareActivities(left, right));
      });
  }

  protected editActivity(activity: Activity): void {
    this.editingActivityId = activity.id;
    this.isActivityPanelOpen = true;
    this.newActivity = {
      title: activity.title,
      startTime: activity.startTime,
      endTime: activity.endTime,
      assignee: activity.assignee,
      visibility: activity.visibility,
      completed: activity.completed,
      location: activity.location,
      description: activity.description,
      date: activity.date,
      reminderMinutes: activity.reminderMinutes
    };
    this.selectDate(activity.date);
  }

  protected cancelActivityEdit(): void {
    this.resetActivityForm();
  }

  protected get isEditingActivity(): boolean {
    return this.editingActivityId !== null;
  }

  protected get isEditingGeneralPending(): boolean {
    return this.editingGeneralPendingId !== null;
  }

  protected get isEditingFinancialEntry(): boolean {
    return this.editingFinancialEntryId !== null;
  }

  protected addGeneralPending(): void {
    const title = this.newGeneralPending.title.trim();
    const assignee = this.newGeneralPending.assignee;
    const description = this.newGeneralPending.description.trim();
    const date = this.newGeneralPending.date;

    if (!title || !assignee || !date) {
      return;
    }

    const pending: GeneralPending = {
      id: this.editingGeneralPendingId ?? Date.now(),
      title,
      assignee,
      description,
      date
    };

    const request =
      this.editingGeneralPendingId === null
        ? this.agendaApi.createGeneralPending({ title, assignee, description, date })
        : this.agendaApi.updateGeneralPending(this.editingGeneralPendingId, {
            title,
            assignee,
            description,
            date
          });

    request.subscribe((savedPending) => {
      this.generalPendings = (
        this.editingGeneralPendingId === null
          ? [...this.generalPendings, savedPending]
          : this.generalPendings.map((existingPending) =>
              existingPending.id === this.editingGeneralPendingId ? savedPending : existingPending
            )
      ).sort((left, right) => left.title.localeCompare(right.title));
      this.resetGeneralPendingForm();
    });
  }

  protected removeGeneralPending(pendingId: number): void {
    this.agendaApi.deleteGeneralPending(pendingId).subscribe(() => {
      this.generalPendings = this.generalPendings.filter((pending) => pending.id !== pendingId);

      if (this.editingGeneralPendingId === pendingId) {
        this.resetGeneralPendingForm();
      }
    });
  }

  protected editGeneralPending(pending: GeneralPending): void {
    this.isPendingPanelOpen = true;
    this.editingGeneralPendingId = pending.id;
    this.newGeneralPending = {
      title: pending.title,
      assignee: pending.assignee,
      description: pending.description,
      date: pending.date
    };
  }

  protected cancelGeneralPendingEdit(): void {
    this.resetGeneralPendingForm();
  }

  protected addFinancialEntry(): void {
    const title = this.newFinancialEntry.title.trim();
    const type = this.newFinancialEntry.type;
    const amount = Number(this.newFinancialEntry.amount);
    const assignee = this.newFinancialEntry.assignee;
    const description = this.newFinancialEntry.description.trim();
    const date = this.newFinancialEntry.date;
    const participationPercentage = this.normalizeParticipationPercentageInput(
      this.newFinancialEntry.participationPercentage
    );

    if (!title || !type || !assignee || !date || !Number.isFinite(amount) || amount <= 0) {
      return;
    }

    const entry: FinancialEntry = {
      id: this.editingFinancialEntryId ?? Date.now(),
      title,
      type,
      amount,
      assignee,
      description,
      date,
      participationPercentage,
      participantAmount: this.calculateParticipantAmount(amount, participationPercentage)
    };

    const request =
      this.editingFinancialEntryId === null
        ? this.agendaApi.createFinancialEntry({
            title,
            type,
            amount,
            assignee,
            description,
            date,
            participationPercentage,
            participantAmount: entry.participantAmount
          })
        : this.agendaApi.updateFinancialEntry(this.editingFinancialEntryId, {
            title,
            type,
            amount,
            assignee,
            description,
            date,
            participationPercentage,
            participantAmount: entry.participantAmount
          });

    request.subscribe((savedEntry) => {
      this.financialEntries = (
        this.editingFinancialEntryId === null
          ? [...this.financialEntries, savedEntry]
          : this.financialEntries.map((existingEntry) =>
              existingEntry.id === this.editingFinancialEntryId ? savedEntry : existingEntry
            )
      ).sort((left, right) => this.compareFinancialEntries(left, right));
      this.resetFinancialEntryForm();
    });
  }

  protected editFinancialEntry(entry: FinancialEntry): void {
    this.isFinancePanelOpen = true;
    this.editingFinancialEntryId = entry.id;
    this.newFinancialEntry = {
      title: entry.title,
      type: entry.type,
      amount: entry.amount,
      assignee: entry.assignee,
      description: entry.description,
      date: entry.date,
      participationPercentage: entry.participationPercentage,
      participantAmount: entry.participantAmount
    };
  }

  protected cancelFinancialEntryEdit(): void {
    this.resetFinancialEntryForm();
  }

  protected removeFinancialEntry(entryId: number): void {
    this.agendaApi.deleteFinancialEntry(entryId).subscribe(() => {
      this.financialEntries = this.financialEntries.filter((entry) => entry.id !== entryId);

      if (this.editingFinancialEntryId === entryId) {
        this.resetFinancialEntryForm();
      }
    });
  }

  protected get currentMonthLabel(): string {
    return `${this.monthNames[this.currentMonth.getMonth()]} ${this.currentMonth.getFullYear()}`;
  }

  protected get filteredFinancialEntries(): FinancialEntry[] {
    return this.financialEntries.filter((entry) => {
      if (this.financeFilters.startDate && entry.date < this.financeFilters.startDate) {
        return false;
      }

      if (this.financeFilters.endDate && entry.date > this.financeFilters.endDate) {
        return false;
      }

      return true;
    });
  }

  protected get financialReportSummary(): {
    income: number;
    expense: number;
    net: number;
    participantTotal: number;
    entryCount: number;
  } {
    return this.filteredFinancialEntries.reduce(
      (summary, entry) => {
        if (entry.type === 'income') {
          summary.income += entry.amount;
        } else {
          summary.expense += entry.amount;
        }

        summary.participantTotal += entry.participantAmount;
        summary.entryCount += 1;
        summary.net = summary.income - summary.expense;
        return summary;
      },
      {
        income: 0,
        expense: 0,
        net: 0,
        participantTotal: 0,
        entryCount: 0
      }
    );
  }

  protected get currentPeriodLabel(): string {
    return this.viewMode === 'month' ? this.currentMonthLabel : this.currentWeekLabel;
  }

  protected get currentWeekLabel(): string {
    const weekStart = this.selectedWeekStart;
    const weekEnd = this.getWeekEnd(weekStart);
    return `${this.formatLongDate(weekStart)} - ${this.formatLongDate(weekEnd)}`;
  }

  protected get calendarDays(): CalendarDay[] {
    const firstDayOfMonth = this.startOfMonth(this.currentMonth);
    const startOffset = (firstDayOfMonth.getDay() + 6) % 7;
    const gridStart = new Date(firstDayOfMonth);
    gridStart.setDate(firstDayOfMonth.getDate() - startOffset);
    const lastDayOfMonth = new Date(
      this.currentMonth.getFullYear(),
      this.currentMonth.getMonth() + 1,
      0
    );
    const endOffset = (6 - ((lastDayOfMonth.getDay() + 6) % 7) + 6) % 6;
    const gridEnd = this.addDays(lastDayOfMonth, endOffset);
    const today = this.toIsoDate(new Date());
    const days: CalendarDay[] = [];

    for (let date = new Date(gridStart); date <= gridEnd; date = this.addDays(date, 1)) {
      if (date.getDay() === 0) {
        continue;
      }

      const isoDate = this.toIsoDate(date);
      days.push({
        date: new Date(date),
        isoDate,
        dayNumber: date.getDate(),
        inCurrentMonth: date.getMonth() === this.currentMonth.getMonth(),
        isToday: isoDate === today,
        activities: this.getActivitiesForDate(isoDate)
      });
    }

    return days;
  }

  protected get selectedDateActivities(): Activity[] {
    return this.getActivitiesForDate(this.selectedDate);
  }

  protected get selectedWeekStart(): Date {
    return this.getWeekStart(this.selectedDateAsDate);
  }

  protected get weeklyCalendarDays(): CalendarDay[] {
    const weekStart = this.selectedWeekStart;
    const today = this.toIsoDate(new Date());

    return Array.from({ length: 6 }, (_, index) => {
      const date = this.addDays(weekStart, index);
      const isoDate = this.toIsoDate(date);

      return {
        date,
        isoDate,
        dayNumber: date.getDate(),
        inCurrentMonth: date.getMonth() === this.currentMonth.getMonth(),
        isToday: isoDate === today,
        activities: this.getActivitiesForDate(isoDate)
      };
    });
  }

  protected get weeklyGroups(): WeekGroup[] {
    const monthDays = this.calendarDays.filter((day) => day.inCurrentMonth);
    const weeks = new Map<string, Activity[]>();

    for (const day of monthDays) {
      const weekStart = this.getWeekStart(day.date);
      const label = `${this.formatShortDate(weekStart)} - ${this.formatShortDate(
        this.getWeekEnd(weekStart)
      )}`;

      const existingActivities = weeks.get(label) ?? [];
      weeks.set(label, [...existingActivities, ...day.activities]);
    }

    return Array.from(weeks.entries()).map(([label, activities]) => ({
      label,
      activities: activities.sort((left, right) => this.compareActivities(left, right))
    }));
  }

  protected trackByIsoDate(_index: number, day: CalendarDay): string {
    return day.isoDate;
  }

  protected trackByActivity(_index: number, activity: Activity): number {
    return activity.id;
  }

  protected trackByPending(_index: number, pending: GeneralPending): number {
    return pending.id;
  }

  protected trackByFinancialEntry(_index: number, entry: FinancialEntry): number {
    return entry.id;
  }

  protected formatDayLabel(isoDate: string): string {
    if (!this.isValidIsoDate(isoDate)) {
      return 'Sin fecha';
    }

    const date = new Date(`${isoDate}T00:00:00`);
    return `${this.fullWeekDayNames[date.getDay()]} ${date.getDate()}`;
  }

  protected formatFullDateLabel(isoDate: string): string {
    if (!this.isValidIsoDate(isoDate)) {
      return 'Sin fecha';
    }

    const date = new Date(`${isoDate}T00:00:00`);
    return `${this.fullWeekDayNames[date.getDay()]} ${date.getDate()} ${this.monthNames[date.getMonth()]} ${date.getFullYear()}`;
  }

  protected formatTimeLabel(time: string): string {
    if (!time) {
      return 'Sin hora';
    }

    const [hoursText, minutesText] = time.split(':');
    const hours = Number(hoursText);
    const suffix = hours >= 12 ? 'PM' : 'AM';
    const normalizedHour = hours % 12 || 12;
    return `${normalizedHour}:${minutesText} ${suffix}`;
  }

  protected formatWeekDayHeader(day: CalendarDay): string {
    return `${this.weekDayNames[(day.date.getDay() + 6) % 7]} ${day.dayNumber}`;
  }

  protected getActivitiesForTimeSlot(isoDate: string, time: string): Activity[] {
    return this.getActivitiesForDate(isoDate).filter((activity) => activity.startTime === time);
  }

  protected getActivitiesForWeekHourSlot(isoDate: string, time: string): Activity[] {
    return this.getActivitiesForDate(isoDate).filter(
      (activity) => this.getHourSlot(activity.startTime) === time
    );
  }

  protected getActivitiesForWeekDay(isoDate: string): Activity[] {
    return this.getActivitiesForDate(isoDate);
  }

  protected getWeekActivityTop(activity: Activity): number {
    const startMinutes = this.parseTimeToMinutes(activity.startTime);
    const dayStartMinutes = this.weeklyHourStart * 60;
    return ((startMinutes - dayStartMinutes) / 60) * this.weeklyRowHeight;
  }

  protected getWeekActivityHeight(activity: Activity): number {
    const startMinutes = this.parseTimeToMinutes(activity.startTime);
    const endMinutes = this.parseTimeToMinutes(activity.endTime);
    const durationMinutes = Math.max(endMinutes - startMinutes, 30);
    return (durationMinutes / 60) * this.weeklyRowHeight;
  }

  protected formatActivityTimeRange(activity: Activity): string {
    return `${this.formatTimeLabel(activity.startTime)} - ${this.formatTimeLabel(activity.endTime)}`;
  }

  protected getReminderLabel(reminderMinutes: number | null): string {
    if (reminderMinutes === null) {
      return 'Sin notificacion';
    }

    return (
      this.reminderOptions.find((option) => option.value === reminderMinutes)?.label ??
      `${reminderMinutes} minuto${reminderMinutes === 1 ? '' : 's'} antes`
    );
  }

  protected getAssigneeClassName(assignee: string): string {
    return `assignee-${assignee.toLowerCase()}`;
  }

  protected getFinancialTypeLabel(type: FinancialEntry['type']): string {
    return type === 'income' ? 'Ingreso' : 'Egreso';
  }

  protected clearFinanceFilters(): void {
    this.financeFilters = {
      startDate: '',
      endDate: ''
    };
  }

  protected formatParticipationLabel(entry: FinancialEntry): string {
    if (entry.participationPercentage === null) {
      return 'Sin porcentaje';
    }

    return `${entry.participationPercentage}% = ${this.formatCurrency(entry.participantAmount)}`;
  }

  protected formatCurrency(amount: number): string {
    return new Intl.NumberFormat('es-CO', {
      style: 'currency',
      currency: 'COP',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0
    }).format(amount);
  }

  private get selectedDateAsDate(): Date {
    return new Date(`${this.selectedDate}T00:00:00`);
  }

  private getActivitiesForDate(isoDate: string): Activity[] {
    return this.activities.filter((activity) => activity.date === isoDate);
  }

  private loadSession(): void {
    this.isAuthLoading = true;
    this.agendaApi.getSessionStatus().subscribe({
      next: (session) => {
        this.isAuthLoading = false;
        this.applySession(session);

        if (session.authenticated) {
          this.loadRemoteData();
        }
      },
      error: () => {
        this.isAuthLoading = false;
        this.authError = 'No fue posible validar la sesion.';
      }
    });
  }

  private loadPublicProfile(username: string): void {
    this.isAuthLoading = true;
    this.publicProfileState = 'loading';
    this.agendaApi.getPublicProfile(username).subscribe({
      next: (profile) => {
        this.isAuthLoading = false;
        this.applyPublicProfile(profile);
      },
      error: (error) => {
        this.isAuthLoading = false;
        if (error?.status === 404) {
          this.publicProfileState = 'not-found';
          return;
        }

        this.publicProfileState = 'disabled';
      }
    });
  }

  private loadRemoteData(): void {
    forkJoin({
      activities: this.agendaApi.getActivities().pipe(catchError(() => of([] as Activity[]))),
      generalPendings: this.agendaApi
        .getGeneralPendings()
        .pipe(catchError(() => of([] as GeneralPending[]))),
      financialEntries: this.loadFinancialEntriesRequest()
    }).subscribe(({ activities, generalPendings, financialEntries }) => {
      this.activities = activities.sort((left, right) => this.compareActivities(left, right));
      this.generalPendings = generalPendings.sort((left, right) =>
        this.compareGeneralPendings(left, right)
      );
      this.financialEntries = financialEntries.sort((left, right) =>
        this.compareFinancialEntries(left, right)
      );
    });
  }

  private toActivityPayload(activity: Activity): Omit<Activity, 'id'> {
    return {
      title: activity.title,
      startTime: activity.startTime,
      endTime: activity.endTime,
      assignee: this.getCurrentAssignee(),
      visibility: activity.visibility,
      completed: activity.completed,
      location: activity.location,
      description: activity.description,
      date: activity.date,
      reminderMinutes: activity.reminderMinutes
    };
  }

  private resetActivityForm(): void {
    this.editingActivityId = null;
    this.newActivity = this.buildEmptyActivity();
  }

  private resetGeneralPendingForm(): void {
    this.editingGeneralPendingId = null;
    this.newGeneralPending = this.buildEmptyGeneralPending();
  }

  private resetFinancialEntryForm(): void {
    this.editingFinancialEntryId = null;
    this.newFinancialEntry = this.buildEmptyFinancialEntry();
  }

  private loadFinancialEntries(): void {
    this.loadFinancialEntriesRequest().subscribe((financialEntries) => {
      this.financialEntries = financialEntries.sort((left, right) =>
        this.compareFinancialEntries(left, right)
      );
    });
  }

  private loadFinancialEntriesRequest() {
    return this.agendaApi.getFinancialEntries().pipe(catchError(() => of([] as FinancialEntry[])));
  }

  private handleAuthenticatedSession(session: AuthSession): void {
    this.applySession(session);
    this.closeAllPanels();
    this.loadRemoteData();
  }

  private applySession(session: AuthSession): void {
    this.currentUser = session.user;
    this.canRegister = session.canRegister;
    this.authError = '';

    if (this.currentUser) {
      this.notificationSettingsForm = {
        whatsappNumber: this.currentUser.whatsappNumber,
        whatsappNotificationsEnabled: this.currentUser.whatsappNotificationsEnabled,
        telegramChatId: this.currentUser.telegramChatId,
        telegramNotificationsEnabled: this.currentUser.telegramNotificationsEnabled
      };
      this.syncAssigneeFields(this.currentUser.name);
      return;
    }

    this.notificationSettingsForm = {
      whatsappNumber: '',
      whatsappNotificationsEnabled: false,
      telegramChatId: '',
      telegramNotificationsEnabled: false
    };
    this.authMode = 'login';
  }

  private applyPublicProfile(profile: PublicProfile): void {
    this.publicProfileUser = profile.user;
    this.generalPendings = [];
    this.financialEntries = [];
    this.activities = profile.activities.sort((left, right) => this.compareActivities(left, right));

    if (!profile.found) {
      this.publicProfileState = 'not-found';
      return;
    }

    this.publicProfileState = profile.profileEnabled ? 'ready' : 'disabled';

    if (profile.activities.length > 0) {
      this.selectDate(profile.activities[0].date);
    }
  }

  private syncAssigneeFields(assignee: string): void {
    this.newActivity.assignee = assignee;
    this.newGeneralPending.assignee = assignee;
    this.newFinancialEntry.assignee = assignee;
  }

  private closeAllPanels(): void {
    this.isActivityPanelOpen = false;
    this.isPendingPanelOpen = false;
    this.isFinancePanelOpen = false;
  }

  private getCurrentAssignee(): string {
    return this.currentUser?.name ?? '';
  }

  private getPublicSlugFromLocation(): string {
    const pathSegments = window.location.pathname
      .split('/')
      .map((segment) => segment.trim())
      .filter(Boolean);

    if (pathSegments.length !== 1) {
      return '';
    }

    const [candidate] = pathSegments;
    const normalizedCandidate = candidate.toLowerCase();

    if (
      normalizedCandidate === 'api' ||
      normalizedCandidate.includes('.') ||
      normalizedCandidate.startsWith('_karma_')
    ) {
      return '';
    }

    return decodeURIComponent(normalizedCandidate);
  }

  private buildEmptyActivity(): Omit<Activity, 'id'> {
    return {
      title: '',
      startTime: '',
      endTime: '',
      assignee: this.getCurrentAssignee(),
      visibility: 'private',
      completed: false,
      location: '',
      description: '',
      date: this.selectedDate,
      reminderMinutes: null
    };
  }

  private buildEmptyGeneralPending(): Omit<GeneralPending, 'id'> {
    return {
      title: '',
      assignee: this.getCurrentAssignee(),
      description: '',
      date: this.toIsoDate(new Date())
    };
  }

  private buildEmptyFinancialEntry(): Omit<FinancialEntry, 'id'> {
    return {
      title: '',
      type: 'income',
      amount: 0,
      assignee: this.getCurrentAssignee(),
      description: '',
      date: this.toIsoDate(new Date()),
      participationPercentage: null,
      participantAmount: 0
    };
  }

  private startOfMonth(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), 1);
  }

  private toIsoDate(date: Date): string {
    const year = date.getFullYear();
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  private normalizeCalendarDate(date: Date): Date {
    return date.getDay() === 0 ? this.addDays(date, -1) : date;
  }

  private isValidIsoDate(value: string): boolean {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      return false;
    }

    const date = new Date(`${value}T00:00:00`);
    return !Number.isNaN(date.getTime());
  }

  private getWeekStart(date: Date): Date {
    const weekStart = new Date(date);
    const dayIndex = (date.getDay() + 6) % 7;
    weekStart.setDate(date.getDate() - dayIndex);
    return weekStart;
  }

  private getWeekEnd(date: Date): Date {
    return this.addDays(this.getWeekStart(date), 5);
  }

  private addDays(date: Date, days: number): Date {
    const result = new Date(date);
    result.setDate(result.getDate() + days);
    return result;
  }

  private formatShortDate(date: Date): string {
    return `${date.getDate()} ${this.monthNames[date.getMonth()].slice(0, 3)}`;
  }

  private formatLongDate(date: Date): string {
    return `${date.getDate()} ${this.monthNames[date.getMonth()]} ${date.getFullYear()}`;
  }

  private buildTimeOptions(): string[] {
    const times: string[] = [];

    for (let hour = 8; hour <= 18; hour += 1) {
      times.push(`${`${hour}`.padStart(2, '0')}:00`);

      if (hour < 18) {
        times.push(`${`${hour}`.padStart(2, '0')}:30`);
      }
    }

    return times;
  }

  private buildWeeklyTimeOptions(): string[] {
    const times: string[] = [];

    for (let hour = 8; hour <= 18; hour += 1) {
      times.push(`${`${hour}`.padStart(2, '0')}:00`);
    }

    return times;
  }

  private getHourSlot(time: string): string {
    const [hoursText] = time.split(':');
    return `${hoursText}:00`;
  }

  private parseTimeToMinutes(time: string): number {
    const [hoursText, minutesText] = time.split(':');
    return Number(hoursText) * 60 + Number(minutesText);
  }

  private getDefaultEndTime(startTime: string): string {
    const currentIndex = this.timeOptions.indexOf(startTime);

    if (currentIndex === -1 || currentIndex === this.timeOptions.length - 1) {
      return startTime;
    }

    return this.timeOptions[currentIndex + 1];
  }

  private compareTimes(left: string, right: string): number {
    return left.localeCompare(right);
  }

  private compareActivities(left: Activity, right: Activity): number {
    const leftKey = `${left.date}|${left.startTime}|${left.endTime}|${left.completed ? 1 : 0}|${left.title}`;
    const rightKey = `${right.date}|${right.startTime}|${right.endTime}|${right.completed ? 1 : 0}|${right.title}`;
    return leftKey.localeCompare(rightKey);
  }

  private compareGeneralPendings(left: GeneralPending, right: GeneralPending): number {
    const dateComparison = (right.date ?? '').localeCompare(left.date ?? '');
    if (dateComparison !== 0) {
      return dateComparison;
    }

    return `${left.title}|${left.assignee}`.localeCompare(`${right.title}|${right.assignee}`);
  }

  private compareFinancialEntries(left: FinancialEntry, right: FinancialEntry): number {
    const dateComparison = (right.date ?? '').localeCompare(left.date ?? '');
    if (dateComparison !== 0) {
      return dateComparison;
    }

    return `${left.type}|${left.title}|${left.amount}|${left.assignee}`.localeCompare(
      `${right.type}|${right.title}|${right.amount}|${right.assignee}`
    );
  }

  private normalizeParticipationPercentageInput(value: number | null): number | null {
    if (value === null || !Number.isFinite(Number(value))) {
      return null;
    }

    const normalizedValue = Number(value);
    if (normalizedValue < 0 || normalizedValue > 100) {
      return null;
    }

    return normalizedValue;
  }

  private calculateParticipantAmount(amount: number, participationPercentage: number | null): number {
    if (participationPercentage === null) {
      return 0;
    }

    return Math.round(amount * (participationPercentage / 100));
  }
}
