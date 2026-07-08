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
  CompanyService,
  CompanyContext,
  CompanyProfessional,
  FinancialEntry,
  GeneralPending,
  PublicProfile,
  ServiceRole,
  SystemAccountSummary,
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
  protected readonly weeklyRowHeight = 72;

  protected currentMonth = this.startOfMonth(new Date());
  protected selectedDate = this.toIsoDate(this.normalizeCalendarDate(new Date()));
  protected currentUser: AuthUser | null = null;
  protected publicProfileUser: PublicProfile['user'] = null;
  protected publicProfileProfessionals: NonNullable<PublicProfile['professionals']> = [];
  protected publicProfileServices: CompanyService[] = [];
  protected publicProfileHours = { start: 8, end: 18 };
  protected canRegister = false;
  protected authMode: 'login' | 'register' = 'login';
  protected authError = '';
  protected authMessage = '';
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
  protected companyContext: CompanyContext | null = null;
  protected companySettingsError = '';
  protected companySettingsMessage = '';
  protected isSavingCompanyProfile = false;
  protected isSavingSubscription = false;
  protected isSavingProfessional = false;
  protected isSavingServiceRole = false;
  protected isSavingService = false;
  protected editingProfessionalId: number | null = null;
  protected editingServiceRoleId: number | null = null;
  protected editingServiceId: number | null = null;
  protected systemAccounts: SystemAccountSummary[] = [];
  protected isLoadingSystemAccounts = false;
  protected isSavingSystemAccount = false;
  protected verificationToken = '';
  protected authRouteMode: 'login' | 'register' | null = null;
  protected superAdminTab: 'overview' | 'accounts' = 'overview';
  protected companyAdminTab: 'agenda' | 'config' = 'agenda';
  protected editingSystemAccountId: number | null = null;
  protected isSubmittingPublicBooking = false;
  protected isPublicBookingModalOpen = false;
  protected loginForm = {
    username: '',
    password: ''
  };
  protected registerForm = {
    name: '',
    username: '',
    email: '',
    companyName: '',
    accountType: 'business' as 'business' | 'independent',
    password: ''
  };
  protected notificationSettingsForm = {
    whatsappNumber: '',
    whatsappNotificationsEnabled: false,
    telegramChatId: '',
    telegramNotificationsEnabled: false
  };
  protected companyProfileForm = {
    name: '',
    workingHourStart: 8,
    workingHourEnd: 18
  };
  protected companySubscriptionForm = {
    planName: 'Basico empresarial',
    planCode: 'basic',
    status: 'active',
    monthlyPrice: 150000,
    professionalLimit: 4,
    renewalDay: new Date().getDate()
  };
  protected systemAccountForm = {
    companyId: 0,
    companyStatus: 'active' as 'active' | 'inactive' | 'suspended',
    planName: '',
    planCode: '',
    subscriptionStatus: 'active' as 'active' | 'trial' | 'suspended' | 'cancelled',
    monthlyPrice: 0,
    professionalLimit: 4,
    renewalDay: null as number | null
  };
  protected professionalForm = {
    name: '',
    email: '',
    phone: '',
    active: true,
    roleIds: [] as number[]
  };
  protected serviceRoleForm = {
    name: '',
    active: true
  };
  protected serviceForm = {
    name: '',
    roleId: 0,
    durationMinutes: 60,
    description: '',
    active: true
  };
  protected publicBookingForm = {
    serviceId: 0,
    professionalId: 0,
    customerName: '',
    customerPhone: '',
    date: '',
    startTime: '',
    notes: ''
  };
  protected activityBookingForm = {
    serviceId: 0,
    customerName: '',
    customerPhone: ''
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
    const verificationToken = this.getVerificationTokenFromLocation();
    if (verificationToken) {
      this.verificationToken = verificationToken;
      this.verifyEmailToken(verificationToken);
      return;
    }

    const authRouteMode = this.getAuthRouteModeFromLocation();
    if (authRouteMode) {
      this.authRouteMode = authRouteMode;
      this.authMode = authRouteMode;
      this.isAuthLoading = false;
      this.loadSession(false);
      return;
    }

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
    if (this.isProfessionalUser && this.currentUser?.name) {
      return [this.currentUser.name];
    }

    const companyProfessionals = this.companyContext?.professionals
      .filter((professional) => professional.active)
      .map((professional) => professional.name) ?? [];

    if (companyProfessionals.length > 0) {
      return companyProfessionals;
    }

    return this.currentUser ? [this.currentUser.name] : [];
  }

  protected get isAuthenticated(): boolean {
    return this.currentUser !== null;
  }

  protected get isAuthRoute(): boolean {
    return this.authRouteMode !== null;
  }

  protected get isSystemAdmin(): boolean {
    return Boolean(this.currentUser?.isSystemAdmin);
  }

  protected get isProfessionalUser(): boolean {
    return !this.isSystemAdmin && (this.currentUser?.companyRole ?? '') === 'professional';
  }

  protected get isCompanyAdminUser(): boolean {
    return !this.isSystemAdmin && !this.isProfessionalUser;
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

  protected get activeProfessionals(): CompanyProfessional[] {
    return this.companyContext?.professionals.filter((professional) => professional.active) ?? [];
  }

  protected get companyStats() {
    return this.companyContext?.stats ?? {
      activeProfessionals: 0,
      availableSlots: 0
    };
  }

  protected get isEditingProfessional(): boolean {
    return this.editingProfessionalId !== null;
  }

  protected get isEditingServiceRole(): boolean {
    return this.editingServiceRoleId !== null;
  }

  protected get isEditingService(): boolean {
    return this.editingServiceId !== null;
  }

  protected get verifiedSystemAccountsCount(): number {
    return this.systemAccounts.filter((account) => account.emailVerified).length;
  }

  protected get currentWorkingHours(): { start: number; end: number } {
    if (this.isPublicProfileMode) {
      return {
        start: this.publicProfileHours.start,
        end: this.publicProfileHours.end
      };
    }

    return {
      start: this.companyContext?.company.workingHourStart ?? 8,
      end: this.companyContext?.company.workingHourEnd ?? 18
    };
  }

  protected get timeOptions(): string[] {
    return this.buildTimeOptions(this.currentWorkingHours.start, this.currentWorkingHours.end);
  }

  protected get hourOptions(): string[] {
    return this.buildWeeklyTimeOptions(0, 23);
  }

  protected get weeklyTimeOptions(): string[] {
    return this.buildWeeklyTimeOptions(this.currentWorkingHours.start, this.currentWorkingHours.end);
  }

  protected get editingSystemAccount(): SystemAccountSummary | null {
    return (
      this.systemAccounts.find((account) => account.companyId === this.editingSystemAccountId) ?? null
    );
  }

  protected get publicProfessionals() {
    return this.publicProfileUser ? (this.publicProfileProfessionals ?? []) : [];
  }

  protected get serviceRoles(): ServiceRole[] {
    return this.companyContext?.serviceRoles ?? [];
  }

  protected get companyServices(): CompanyService[] {
    return this.companyContext?.services ?? [];
  }

  protected get activeServiceOptions(): CompanyService[] {
    return this.companyServices.filter((service) => service.active);
  }

  protected get selectedActivityService(): CompanyService | null {
    return this.activeServiceOptions.find((service) => service.id === this.activityBookingForm.serviceId) ?? null;
  }

  protected get selectedPublicService(): CompanyService | null {
    return this.publicProfileServices.find((service) => service.id === this.publicBookingForm.serviceId) ?? null;
  }

  protected get filteredPublicProfessionals() {
    const selectedService = this.selectedPublicService;

    if (!selectedService?.roleId) {
      return this.publicProfileProfessionals ?? [];
    }

    return (this.publicProfileProfessionals ?? []).filter((professional) =>
      professional.roleIds.includes(selectedService.roleId ?? 0)
    );
  }

  protected get selectedPublicProfessionalName(): string {
    if (!this.publicBookingForm.professionalId) {
      return 'Asignacion automatica';
    }

    return (
      this.filteredPublicProfessionals.find(
        (professional) => professional.id === this.publicBookingForm.professionalId
      )?.name ?? 'Profesional no disponible'
    );
  }

  protected get publicBookingEndTime(): string {
    const selectedService = this.selectedPublicService;

    if (!selectedService || !this.publicBookingForm.startTime) {
      return '';
    }

    return this.getEndTimeByDuration(this.publicBookingForm.startTime, selectedService.durationMinutes);
  }

  protected get publicBookingMinDate(): string {
    return this.publicMinimumIsoDate;
  }

  protected setAuthMode(mode: 'login' | 'register'): void {
    this.authError = '';
    this.authMessage = '';
    this.authMode = mode;
  }

  protected navigateToAuth(mode: 'login' | 'register'): void {
    this.authRouteMode = mode;
    this.authMode = mode;
    this.authError = '';
    this.authMessage = '';
    window.history.pushState({}, '', mode === 'login' ? '/login' : '/register');
  }

  protected navigateToLanding(): void {
    this.authRouteMode = null;
    this.authError = '';
    this.authMessage = '';
    window.history.pushState({}, '', '/');
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

  protected reloadSystemAccounts(): void {
    if (!this.isSystemAdmin) {
      return;
    }

    this.isLoadingSystemAccounts = true;
    this.agendaApi.getSystemAccounts().subscribe({
      next: (accounts) => {
        this.isLoadingSystemAccounts = false;
        this.systemAccounts = accounts;
      },
      error: () => {
        this.isLoadingSystemAccounts = false;
        this.companySettingsError = 'No fue posible cargar las cuentas registradas.';
      }
    });
  }

  protected openSystemAccountEditor(account: SystemAccountSummary): void {
    if (!account.companyId || account.isSystemAdmin) {
      return;
    }

    this.editingSystemAccountId = account.companyId;
    this.companySettingsError = '';
    this.companySettingsMessage = '';
    this.systemAccountForm = {
      companyId: account.companyId,
      companyStatus: account.companyStatus,
      planName: account.planName || 'Plan gratuito',
      planCode: account.planCode || 'free',
      subscriptionStatus: account.subscriptionStatus,
      monthlyPrice: account.monthlyPrice || 0,
      professionalLimit: Math.max(account.professionalLimit || 4, 4),
      renewalDay: account.renewalDay
    };
  }

  protected closeSystemAccountEditor(): void {
    this.editingSystemAccountId = null;
    this.isSavingSystemAccount = false;
  }

  protected saveSystemAccount(): void {
    if (!this.systemAccountForm.companyId) {
      return;
    }

    this.isSavingSystemAccount = true;
    this.companySettingsError = '';
    this.companySettingsMessage = '';
    this.agendaApi
      .updateSystemAccount({
        companyId: this.systemAccountForm.companyId,
        companyStatus: this.systemAccountForm.companyStatus,
        planName: this.systemAccountForm.planName.trim(),
        planCode: this.systemAccountForm.planCode.trim().toLowerCase(),
        subscriptionStatus: this.systemAccountForm.subscriptionStatus,
        monthlyPrice: Number(this.systemAccountForm.monthlyPrice) || 0,
        professionalLimit: Math.max(Number(this.systemAccountForm.professionalLimit) || 4, 4),
        renewalDay: this.systemAccountForm.renewalDay
      })
      .subscribe({
        next: (accounts) => {
          this.systemAccounts = accounts;
          this.isSavingSystemAccount = false;
          this.companySettingsMessage = 'Cuenta actualizada por el superadmin.';
          this.closeSystemAccountEditor();
        },
        error: (error) => {
          this.isSavingSystemAccount = false;
          this.companySettingsError =
            error?.error?.message ?? 'No fue posible actualizar la cuenta seleccionada.';
        }
      });
  }

  protected saveCompanyProfile(): void {
    const name = this.companyProfileForm.name.trim();
    const workingHourStart = Number(this.companyProfileForm.workingHourStart);
    const workingHourEnd = Number(this.companyProfileForm.workingHourEnd);

    if (!name) {
      this.companySettingsError = 'Ingresa el nombre de la empresa.';
      this.companySettingsMessage = '';
      return;
    }

    if (workingHourStart < 0 || workingHourStart > 23 || workingHourEnd < 1 || workingHourEnd > 23) {
      this.companySettingsError = 'Define un horario laboral valido.';
      this.companySettingsMessage = '';
      return;
    }

    if (workingHourEnd <= workingHourStart) {
      this.companySettingsError = 'La hora final debe ser mayor a la hora inicial.';
      this.companySettingsMessage = '';
      return;
    }

    this.isSavingCompanyProfile = true;
    this.companySettingsError = '';
    this.companySettingsMessage = '';
    this.agendaApi
      .updateCompany({
        name,
        workingHourStart,
        workingHourEnd
      })
      .subscribe({
        next: (context) => {
          this.isSavingCompanyProfile = false;
          this.applyCompanyContext(context);
          this.companySettingsMessage = 'Empresa actualizada.';
        },
        error: (error) => {
          this.isSavingCompanyProfile = false;
          this.companySettingsError = error?.error?.message ?? 'No fue posible actualizar la empresa.';
        }
      });
  }

  protected saveSubscription(): void {
    this.isSavingSubscription = true;
    this.companySettingsError = '';
    this.companySettingsMessage = '';
    this.agendaApi
      .updateSubscription({
        planName: this.companySubscriptionForm.planName.trim(),
        planCode: this.companySubscriptionForm.planCode.trim().toLowerCase(),
        status: this.companySubscriptionForm.status as CompanyContext['subscription']['status'],
        monthlyPrice: Number(this.companySubscriptionForm.monthlyPrice) || 0,
        professionalLimit: Number(this.companySubscriptionForm.professionalLimit) || 4,
        renewalDay: Number(this.companySubscriptionForm.renewalDay) || null
      })
      .subscribe({
        next: (context) => {
          this.isSavingSubscription = false;
          this.applyCompanyContext(context);
          this.companySettingsMessage = 'Suscripcion actualizada.';
        },
        error: (error) => {
          this.isSavingSubscription = false;
          this.companySettingsError =
            error?.error?.message ?? 'No fue posible actualizar la suscripcion.';
        }
      });
  }

  protected saveProfessional(): void {
    const professionalPayload = {
      name: this.professionalForm.name.trim(),
      email: this.professionalForm.email.trim(),
      phone: this.professionalForm.phone.trim(),
      active: this.professionalForm.active,
      roleIds: this.professionalForm.roleIds
    };

    if (!professionalPayload.name) {
      this.companySettingsError = 'Ingresa el nombre del profesional.';
      this.companySettingsMessage = '';
      return;
    }

    this.isSavingProfessional = true;
    this.companySettingsError = '';
    this.companySettingsMessage = '';

    const request =
      this.editingProfessionalId === null
        ? this.agendaApi.createProfessional(professionalPayload)
        : this.agendaApi.updateProfessional(this.editingProfessionalId, professionalPayload);

    request.subscribe({
      next: (professional) => {
        const wasEditing = this.editingProfessionalId !== null;
        this.isSavingProfessional = false;
        this.updateProfessionalCollection(professional);
        this.resetProfessionalForm();
        this.companySettingsMessage =
          wasEditing
            ? 'Profesional actualizado.'
            : 'Profesional agregado. Si tiene correo, se envio su acceso para verificacion.';
      },
      error: (error) => {
        this.isSavingProfessional = false;
        this.companySettingsError =
          error?.error?.message ?? 'No fue posible guardar el profesional.';
      }
    });
  }

  protected editProfessional(professional: CompanyProfessional): void {
    this.editingProfessionalId = professional.id;
    this.professionalForm = {
      name: professional.name,
      email: professional.email,
      phone: professional.phone,
      active: professional.active,
      roleIds: [...professional.roleIds]
    };
  }

  protected cancelProfessionalEdit(): void {
    this.resetProfessionalForm();
  }

  protected removeProfessional(professionalId: number): void {
    this.companySettingsError = '';
    this.companySettingsMessage = '';
    this.agendaApi.deleteProfessional(professionalId).subscribe({
      next: () => {
        if (this.companyContext) {
          this.companyContext = {
            ...this.companyContext,
            professionals: this.companyContext.professionals.filter(
              (professional) => professional.id !== professionalId
            )
          };
          this.recalculateCompanyStats();
        }

        if (this.editingProfessionalId === professionalId) {
          this.resetProfessionalForm();
        }

        this.syncAssigneeFields(this.currentAssigneeFallback());
        this.companySettingsMessage = 'Profesional eliminado.';
      },
      error: (error) => {
        this.companySettingsError =
          error?.error?.message ?? 'No fue posible eliminar el profesional.';
      }
    });
  }

  protected dismissCompanySettingsFeedback(): void {
    this.companySettingsError = '';
    this.companySettingsMessage = '';
  }

  protected toggleProfessionalRole(roleId: number, checked: boolean): void {
    const nextRoleIds = checked
      ? Array.from(new Set([...this.professionalForm.roleIds, roleId]))
      : this.professionalForm.roleIds.filter((existingRoleId) => existingRoleId !== roleId);
    this.professionalForm.roleIds = nextRoleIds;
  }

  protected saveServiceRole(): void {
    const payload = {
      name: this.serviceRoleForm.name.trim(),
      active: this.serviceRoleForm.active
    };

    if (!payload.name) {
      this.companySettingsError = 'Ingresa el nombre de la especialidad.';
      this.companySettingsMessage = '';
      return;
    }

    this.isSavingServiceRole = true;
    this.companySettingsError = '';
    this.companySettingsMessage = '';

    const request =
      this.editingServiceRoleId === null
        ? this.agendaApi.createServiceRole(payload)
        : this.agendaApi.updateServiceRole(this.editingServiceRoleId, payload);
    const wasEditing = this.editingServiceRoleId !== null;

    request.subscribe({
      next: (serviceRole) => {
        if (!this.companyContext) {
          this.isSavingServiceRole = false;
          return;
        }

        this.companyContext = {
          ...this.companyContext,
          serviceRoles:
            this.editingServiceRoleId === null
              ? [...this.companyContext.serviceRoles, serviceRole].sort((left, right) => left.name.localeCompare(right.name))
              : this.companyContext.serviceRoles
                  .map((existingRole) => (existingRole.id === serviceRole.id ? serviceRole : existingRole))
                  .sort((left, right) => left.name.localeCompare(right.name))
        };
        this.isSavingServiceRole = false;
        this.resetServiceRoleForm();
        this.companySettingsMessage = wasEditing ? 'Especialidad actualizada.' : 'Especialidad creada.';
      },
      error: (error) => {
        this.isSavingServiceRole = false;
        this.companySettingsError =
          error?.error?.message ??
          (error?.status === 500
            ? 'No fue posible guardar la especialidad. Revisa que la base de datos tenga las tablas service_roles, services y professional_roles.'
            : 'No fue posible guardar la especialidad.');
      }
    });
  }

  protected editServiceRole(role: ServiceRole): void {
    this.editingServiceRoleId = role.id;
    this.serviceRoleForm = {
      name: role.name,
      active: role.active
    };
  }

  protected cancelServiceRoleEdit(): void {
    this.resetServiceRoleForm();
  }

  protected removeServiceRole(roleId: number): void {
    this.companySettingsError = '';
    this.companySettingsMessage = '';
    this.agendaApi.deleteServiceRole(roleId).subscribe({
      next: () => {
        if (this.companyContext) {
          this.companyContext = {
            ...this.companyContext,
            serviceRoles: this.companyContext.serviceRoles.filter((role) => role.id !== roleId)
          };
        }

        if (this.editingServiceRoleId === roleId) {
          this.resetServiceRoleForm();
        }

        this.companySettingsMessage = 'Especialidad eliminada.';
      },
      error: (error) => {
        this.companySettingsError = error?.error?.message ?? 'No fue posible eliminar la especialidad.';
      }
    });
  }

  protected saveService(): void {
    const payload = {
      name: this.serviceForm.name.trim(),
      roleId: Number(this.serviceForm.roleId) || 0,
      durationMinutes: Number(this.serviceForm.durationMinutes) || 0,
      description: this.serviceForm.description.trim(),
      active: this.serviceForm.active
    };

    if (!payload.name || payload.roleId <= 0) {
      this.companySettingsError = 'Completa nombre y especialidad del servicio.';
      this.companySettingsMessage = '';
      return;
    }

    this.isSavingService = true;
    this.companySettingsError = '';
    this.companySettingsMessage = '';

    const request =
      this.editingServiceId === null
        ? this.agendaApi.createService(payload)
        : this.agendaApi.updateService(this.editingServiceId, payload);
    const wasEditing = this.editingServiceId !== null;

    request.subscribe({
      next: (service) => {
        if (!this.companyContext) {
          this.isSavingService = false;
          return;
        }

        this.companyContext = {
          ...this.companyContext,
          services:
            this.editingServiceId === null
              ? [...this.companyContext.services, service].sort((left, right) => left.name.localeCompare(right.name))
              : this.companyContext.services
                  .map((existingService) => (existingService.id === service.id ? service : existingService))
                  .sort((left, right) => left.name.localeCompare(right.name))
        };
        this.isSavingService = false;
        this.resetServiceForm();
        this.companySettingsMessage = wasEditing ? 'Servicio actualizado.' : 'Servicio creado.';
      },
      error: (error) => {
        this.isSavingService = false;
        this.companySettingsError =
          error?.error?.message ??
          (error?.status === 500
            ? 'No fue posible guardar el servicio. Revisa que la base de datos tenga las tablas service_roles, services y professional_roles.'
            : 'No fue posible guardar el servicio.');
      }
    });
  }

  protected editService(service: CompanyService): void {
    this.editingServiceId = service.id;
    this.serviceForm = {
      name: service.name,
      roleId: service.roleId ?? 0,
      durationMinutes: service.durationMinutes,
      description: service.description,
      active: service.active
    };
  }

  protected cancelServiceEdit(): void {
    this.resetServiceForm();
  }

  protected removeService(serviceId: number): void {
    this.companySettingsError = '';
    this.companySettingsMessage = '';
    this.agendaApi.deleteService(serviceId).subscribe({
      next: () => {
        if (this.companyContext) {
          this.companyContext = {
            ...this.companyContext,
            services: this.companyContext.services.filter((service) => service.id !== serviceId)
          };
        }

        if (this.editingServiceId === serviceId) {
          this.resetServiceForm();
        }

        this.companySettingsMessage = 'Servicio eliminado.';
      },
      error: (error) => {
        this.companySettingsError = error?.error?.message ?? 'No fue posible eliminar el servicio.';
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
    this.authMessage = '';
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
    const email = this.registerForm.email.trim().toLowerCase();
    const companyName = this.registerForm.companyName.trim();
    const accountType = this.registerForm.accountType;
    const password = this.registerForm.password;

    if (!name || !username || !email || !password) {
      this.authError = 'Completa nombre, usuario, correo y contrasena.';
      return;
    }

    this.isSubmittingAuth = true;
    this.authError = '';
    this.authMessage = '';
    this.agendaApi
      .registerAccount({
        name,
        username,
        email,
        companyName,
        accountType,
        password
      })
      .subscribe({
      next: (session) => {
        this.isSubmittingAuth = false;
        this.registerForm = {
          name: '',
          username: '',
          email: '',
          companyName: '',
          accountType: 'business',
          password: ''
        };
        this.authMode = 'login';
        this.authRouteMode = 'login';
        window.history.replaceState({}, '', '/login');
        this.authMessage =
          session.message ?? 'Registro creado. Revisa tu correo para verificar tu cuenta.';
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
        this.companyContext = null;
        this.activities = [];
        this.generalPendings = [];
        this.financialEntries = [];
        this.authMode = 'login';
        this.authError = '';
        this.closeAllPanels();
        this.resetActivityForm();
        this.resetGeneralPendingForm();
        this.resetFinancialEntryForm();
        this.resetProfessionalForm();
      }
    });
  }

  protected setViewMode(mode: ViewMode): void {
    if (this.isPublicProfileMode && mode === 'month') {
      this.viewMode = 'week';
      return;
    }

    this.viewMode = mode;
  }

  protected submitPublicBooking(): void {
    const customerName = this.publicBookingForm.customerName.trim();
    const date = this.publicBookingForm.date;
    const startTime = this.publicBookingForm.startTime;

    if (
      !this.publicProfileUser ||
      this.publicBookingForm.serviceId <= 0 ||
      !customerName ||
      !date ||
      !startTime
    ) {
      this.authError = 'Completa los datos de la reserva y selecciona un servicio.';
      this.authMessage = '';
      return;
    }

    this.isSubmittingPublicBooking = true;
    this.authError = '';
    this.authMessage = '';

    this.agendaApi.createPublicBooking({
      username: this.publicProfileUser.username,
      serviceId: this.publicBookingForm.serviceId,
      professionalId: this.publicBookingForm.professionalId > 0 ? this.publicBookingForm.professionalId : null,
      customerName,
      customerPhone: this.publicBookingForm.customerPhone.trim(),
      date,
      startTime,
      notes: this.publicBookingForm.notes.trim()
    }).subscribe({
      next: (response) => {
        this.isSubmittingPublicBooking = false;
        this.authMessage = this.buildPublicBookingFeedbackMessage(response);
        this.publicBookingForm = {
          serviceId: this.publicBookingForm.serviceId,
          professionalId: this.publicBookingForm.professionalId,
          customerName: '',
          customerPhone: '',
          date: '',
          startTime: '',
          notes: ''
        };
        this.isPublicBookingModalOpen = false;
      },
      error: (error) => {
        this.isSubmittingPublicBooking = false;
        this.authError = error?.error?.message ?? 'No fue posible registrar la reserva.';
      }
    });
  }

  private buildPublicBookingFeedbackMessage(response: {
    message: string;
    notifications?: {
      sent?: Array<{ recipient: string; status?: string }>;
      failed?: Array<{ recipient: string; message?: string }>;
      skipped?: string[];
    };
  }): string {
    const notifications = response.notifications;

    if (!notifications) {
      return response.message;
    }

    const details: string[] = [];
    const sent = notifications.sent ?? [];
    const failed = notifications.failed ?? [];
    const skipped = notifications.skipped ?? [];

    if (sent.length > 0) {
      details.push(
        'WhatsApp enviado a: ' +
          sent.map((item) => `${item.recipient}${item.status ? ` (${item.status})` : ''}`).join(', ')
      );
    }

    if (failed.length > 0) {
      details.push(
        'WhatsApp con error en: ' +
          failed
            .map((item) => `${item.recipient}${item.message ? ` (${item.message})` : ''}`)
            .join(', ')
      );
    }

    if (skipped.length > 0) {
      details.push('WhatsApp omitido: ' + skipped.join(', '));
    }

    if (details.length === 0) {
      return response.message;
    }

    return `${response.message} ${details.join(' | ')}`;
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

    if (this.isPreviousPeriodDisabled) {
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
    const baseDate = new Date(`${isoDate}T00:00:00`);
    const normalizedDate = this.isPublicProfileMode
      ? this.normalizePublicCalendarDate(baseDate)
      : this.normalizeCalendarDate(baseDate);
    const clampedDate =
      this.isPublicProfileMode && this.toIsoDate(normalizedDate) < this.publicMinimumIsoDate
        ? new Date(this.publicMinimumDate)
        : normalizedDate;
    const normalizedIsoDate = this.toIsoDate(clampedDate);
    this.selectedDate = normalizedIsoDate;
    this.newActivity.date = normalizedIsoDate;
    this.currentMonth = this.startOfMonth(clampedDate);
  }

  protected openActivityPanel(isoDate: string): void {
    this.selectDate(isoDate);
    this.isActivityPanelOpen = true;
  }

  protected openPublicBookingModal(isoDate: string, startTime = ''): void {
    this.selectDate(isoDate);
    this.authError = '';
    this.authMessage = '';
    const serviceId = this.publicBookingForm.serviceId || this.publicProfileServices[0]?.id || 0;
    this.publicBookingForm = {
      ...this.publicBookingForm,
      serviceId,
      date: this.selectedDate,
      startTime,
      professionalId: this.isProfessionalCompatibleWithSelectedService(this.publicBookingForm.professionalId)
        ? this.publicBookingForm.professionalId
        : 0
    };
    this.syncPublicBookingSelection();
    this.isPublicBookingModalOpen = true;
  }

  protected closePublicBookingModal(): void {
    this.isPublicBookingModalOpen = false;
    this.authError = '';
    this.authMessage = '';
  }

  protected onPublicBookingServiceChange(): void {
    if (!this.isProfessionalCompatibleWithSelectedService(this.publicBookingForm.professionalId)) {
      this.publicBookingForm.professionalId = 0;
    }

    this.syncPublicBookingSelection();
  }

  protected onPublicBookingStartTimeChange(): void {
    this.syncPublicBookingSelection();
  }

  protected onPublicBookingDateChange(): void {
    if (!this.publicBookingForm.date) {
      return;
    }

    this.publicBookingForm.date = this.clampPublicIsoDate(this.publicBookingForm.date);
    this.selectDate(this.publicBookingForm.date);
  }

  protected onActivityServiceChange(): void {
    this.syncActivityEndTimeWithService();
  }

  protected onActivityStartTimeChange(): void {
    this.syncActivityEndTimeWithService();
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
    const title = this.buildActivityTitle();
    const startTime = this.newActivity.startTime;
    const endTime = this.newActivity.endTime;
    const assignee = this.newActivity.assignee;
    const description = this.buildActivityDescription();

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
      professionalId: this.findProfessionalIdByName(assignee),
      visibility: this.newActivity.visibility,
      completed: this.newActivity.completed,
      location: '',
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
    const parsedBookingData = this.parseActivityBookingForm(activity);
    this.activityBookingForm = {
      serviceId: parsedBookingData.serviceId,
      customerName: parsedBookingData.customerName,
      customerPhone: parsedBookingData.customerPhone
    };
    this.newActivity = {
      title: activity.title,
      startTime: activity.startTime,
      endTime: activity.endTime,
      assignee: activity.assignee,
      professionalId: activity.professionalId ?? null,
      visibility: activity.visibility,
      completed: activity.completed,
      location: '',
      description: parsedBookingData.notes,
      date: activity.date,
      reminderMinutes: activity.reminderMinutes
    };
    this.syncActivityEndTimeWithService();
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
      professionalId: this.findProfessionalIdByName(assignee),
      description,
      date
    };

    const request =
      this.editingGeneralPendingId === null
        ? this.agendaApi.createGeneralPending({
            title,
            assignee,
            professionalId: pending.professionalId,
            description,
            date
          })
        : this.agendaApi.updateGeneralPending(this.editingGeneralPendingId, {
            title,
            assignee,
            professionalId: pending.professionalId,
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
      professionalId: pending.professionalId ?? null,
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
      professionalId: this.findProfessionalIdByName(assignee),
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
            professionalId: entry.professionalId,
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
            professionalId: entry.professionalId,
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
      professionalId: entry.professionalId ?? null,
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
    const visibleDays = this.weeklyCalendarDays;

    if (this.isPublicProfileMode && visibleDays.length > 0) {
      return `${this.formatLongDate(visibleDays[0].date)} - ${this.formatLongDate(
        visibleDays[visibleDays.length - 1].date
      )}`;
    }

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

    const days = Array.from({ length: 6 }, (_, index) => {
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

    if (!this.isPublicProfileMode) {
      return days;
    }

    return days.filter((day) => day.isoDate >= this.publicMinimumIsoDate);
  }

  protected get isPreviousPeriodDisabled(): boolean {
    if (!this.isPublicProfileMode || this.viewMode !== 'week') {
      return false;
    }

    return this.toIsoDate(this.selectedWeekStart) <= this.toIsoDate(this.getWeekStart(this.publicMinimumDate));
  }

  protected get weekAgendaGridTemplateColumns(): string {
    return `${this.weekAgendaTimeColumnWidth}px repeat(${Math.max(this.weeklyCalendarDays.length, 1)}, minmax(${this.weekAgendaDayWidth}px, 1fr))`;
  }

  protected get weekDayColumnsTemplateColumns(): string {
    return `repeat(${Math.max(this.weeklyCalendarDays.length, 1)}, minmax(${this.weekAgendaDayWidth}px, 1fr))`;
  }

  protected get weekAgendaMinWidth(): string {
    return `${this.weekAgendaTimeColumnWidth + Math.max(this.weeklyCalendarDays.length, 1) * (this.weekAgendaDayWidth + 6)}px`;
  }

  protected get weekDayColumnsMinWidth(): string {
    return `${Math.max(this.weeklyCalendarDays.length, 1) * (this.weekAgendaDayWidth + 6)}px`;
  }

  protected get weekAgendaTimeColumnWidth(): number {
    return this.isPublicProfileMode ? 56 : 74;
  }

  protected get weekAgendaDayWidth(): number {
    return this.isPublicProfileMode ? 68 : 88;
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
    const dayStartMinutes = this.currentWorkingHours.start * 60;
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

  private get publicMinimumDate(): Date {
    return this.normalizePublicCalendarDate(new Date());
  }

  private get publicMinimumIsoDate(): string {
    return this.toIsoDate(this.publicMinimumDate);
  }

  private clampPublicIsoDate(isoDate: string): string {
    if (!this.isValidIsoDate(isoDate)) {
      return this.publicMinimumIsoDate;
    }

    const normalizedDate = this.normalizePublicCalendarDate(new Date(`${isoDate}T00:00:00`));
    const normalizedIsoDate = this.toIsoDate(normalizedDate);
    return normalizedIsoDate < this.publicMinimumIsoDate ? this.publicMinimumIsoDate : normalizedIsoDate;
  }

  private getActivitiesForDate(isoDate: string): Activity[] {
    return this.activities.filter((activity) => activity.date === isoDate);
  }

  private loadSession(showLoader = true): void {
    if (showLoader) {
      this.isAuthLoading = true;
    }

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

        if (showLoader) {
          this.authError = 'No fue posible validar la sesion.';
        }
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
    if (this.isSystemAdmin) {
      this.isLoadingSystemAccounts = true;
      this.agendaApi.getSystemAccounts().subscribe({
        next: (accounts) => {
          this.systemAccounts = accounts;
          this.isLoadingSystemAccounts = false;
          this.activities = [];
          this.generalPendings = [];
          this.financialEntries = [];
          this.companyContext = null;
        },
        error: () => {
          this.isLoadingSystemAccounts = false;
          this.companySettingsError = 'No fue posible cargar las cuentas registradas.';
        }
      });
      return;
    }

    if (this.isProfessionalUser) {
      this.agendaApi.getActivities().pipe(catchError(() => of([] as Activity[]))).subscribe((activities) => {
        this.activities = activities.sort((left, right) => this.compareActivities(left, right));
        this.generalPendings = [];
        this.financialEntries = [];
        this.companyContext = null;
      });
      return;
    }

    forkJoin({
      activities: this.agendaApi.getActivities().pipe(catchError(() => of([] as Activity[]))),
      generalPendings: this.agendaApi
        .getGeneralPendings()
        .pipe(catchError(() => of([] as GeneralPending[]))),
      financialEntries: this.loadFinancialEntriesRequest(),
      companyContext: this.agendaApi
        .getCompanyContext()
        .pipe(catchError(() => of(null as CompanyContext | null)))
    }).subscribe(({ activities, generalPendings, financialEntries, companyContext }) => {
      this.activities = activities.sort((left, right) => this.compareActivities(left, right));
      this.generalPendings = generalPendings.sort((left, right) =>
        this.compareGeneralPendings(left, right)
      );
      this.financialEntries = financialEntries.sort((left, right) =>
        this.compareFinancialEntries(left, right)
      );

      if (companyContext) {
        this.applyCompanyContext(companyContext);
      }
    });
  }

  private toActivityPayload(activity: Activity): Omit<Activity, 'id'> {
    return {
      title: activity.title,
      startTime: activity.startTime,
      endTime: activity.endTime,
      assignee: activity.assignee,
      professionalId: activity.professionalId ?? this.findProfessionalIdByName(activity.assignee),
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
    this.activityBookingForm = {
      serviceId: 0,
      customerName: '',
      customerPhone: ''
    };
  }

  private resetGeneralPendingForm(): void {
    this.editingGeneralPendingId = null;
    this.newGeneralPending = this.buildEmptyGeneralPending();
  }

  private resetFinancialEntryForm(): void {
    this.editingFinancialEntryId = null;
    this.newFinancialEntry = this.buildEmptyFinancialEntry();
  }

  private resetProfessionalForm(): void {
    this.editingProfessionalId = null;
    this.professionalForm = {
      name: '',
      email: '',
      phone: '',
      active: true,
      roleIds: []
    };
  }

  private resetServiceRoleForm(): void {
    this.editingServiceRoleId = null;
    this.serviceRoleForm = {
      name: '',
      active: true
    };
  }

  private resetServiceForm(): void {
    this.editingServiceId = null;
    this.serviceForm = {
      name: '',
      roleId: this.serviceRoles[0]?.id ?? 0,
      durationMinutes: 60,
      description: '',
      active: true
    };
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

    if (session.authenticated) {
      this.loadRemoteData();
    }
  }

  private applySession(session: AuthSession): void {
    this.currentUser = session.user;
    this.canRegister = session.canRegister;
    this.authError = '';
    this.authMessage = session.message ?? '';

    if (this.currentUser) {
      this.notificationSettingsForm = {
        whatsappNumber: this.currentUser.whatsappNumber,
        whatsappNotificationsEnabled: this.currentUser.whatsappNotificationsEnabled,
        telegramChatId: this.currentUser.telegramChatId,
        telegramNotificationsEnabled: this.currentUser.telegramNotificationsEnabled
      };
      if (this.currentUser.isSystemAdmin) {
        this.companyContext = null;
        this.systemAccounts = [];
      } else if (this.isProfessionalUser) {
        this.companyContext = null;
        this.systemAccounts = [];
        this.syncAssigneeFields(this.currentUser.name);
      } else {
        this.syncAssigneeFields(this.currentUser.name);
      }
      return;
    }

    this.companyContext = null;
    this.systemAccounts = [];
    this.notificationSettingsForm = {
      whatsappNumber: '',
      whatsappNotificationsEnabled: false,
      telegramChatId: '',
      telegramNotificationsEnabled: false
    };
    this.resetProfessionalForm();
    this.authMode = 'login';
  }

  private applyPublicProfile(profile: PublicProfile): void {
    this.publicProfileUser = profile.user;
    this.publicProfileProfessionals = profile.professionals ?? [];
    this.publicProfileServices = (profile.services ?? []).filter((service) => service.active);
    this.publicProfileHours = {
      start: this.normalizeWorkingHour(profile.workingHours?.start, 8),
      end: this.normalizeWorkingHour(profile.workingHours?.end, 18)
    };
    this.generalPendings = [];
    this.financialEntries = [];
    this.activities = profile.activities.sort((left, right) => this.compareActivities(left, right));
    this.publicBookingForm.serviceId = this.publicProfileServices[0]?.id ?? 0;
    this.publicBookingForm.professionalId = 0;
    this.syncPublicBookingSelection();
    this.viewMode = 'week';

    if (!profile.found) {
      this.publicProfileState = 'not-found';
      return;
    }

    this.publicProfileState = profile.profileEnabled ? 'ready' : 'disabled';
    const firstUpcomingActivity = profile.activities.find((activity) => activity.date >= this.publicMinimumIsoDate);
    this.selectDate(firstUpcomingActivity?.date ?? this.publicMinimumIsoDate);
  }

  private applyCompanyContext(context: CompanyContext): void {
    this.companyContext = {
      ...context,
      professionals: [...context.professionals].sort((left, right) => left.name.localeCompare(right.name)),
      serviceRoles: [...context.serviceRoles].sort((left, right) => left.name.localeCompare(right.name)),
      services: [...context.services].sort((left, right) => left.name.localeCompare(right.name))
    };
    this.companyProfileForm = {
      name: context.company.name,
      workingHourStart: context.company.workingHourStart,
      workingHourEnd: context.company.workingHourEnd
    };
    this.companySubscriptionForm = {
      planName: context.subscription.planName,
      planCode: context.subscription.planCode,
      status: context.subscription.status,
      monthlyPrice: context.subscription.monthlyPrice,
      professionalLimit: context.subscription.professionalLimit,
      renewalDay: context.subscription.renewalDay ?? new Date().getDate()
    };

    if (!this.professionalForm.name && this.activeProfessionals.length > 0) {
      this.syncAssigneeFields(this.activeProfessionals[0].name);
    } else {
      this.syncAssigneeFields(this.currentAssigneeFallback());
    }

    if (!this.serviceForm.name) {
      this.resetServiceForm();
    }
  }

  private updateProfessionalCollection(professional: CompanyProfessional): void {
    const wasEditing = this.editingProfessionalId !== null;

    if (!this.companyContext) {
      return;
    }

    this.companyContext = {
      ...this.companyContext,
      professionals: (
        wasEditing
          ? this.companyContext.professionals.map((existingProfessional) =>
              existingProfessional.id === professional.id ? professional : existingProfessional
            )
          : [...this.companyContext.professionals, professional]
      ).sort((left, right) => left.name.localeCompare(right.name))
    };
    this.recalculateCompanyStats();
    this.syncAssigneeFields(this.currentAssigneeFallback());
  }

  private recalculateCompanyStats(): void {
    if (!this.companyContext) {
      return;
    }

    const activeProfessionals = this.companyContext.professionals.filter(
      (professional) => professional.active
    ).length;
    this.companyContext = {
      ...this.companyContext,
      stats: {
        activeProfessionals,
        availableSlots: Math.max(
          this.companyContext.subscription.professionalLimit - activeProfessionals,
          0
        )
      }
    };
  }

  private syncAssigneeFields(assignee: string): void {
    const normalizedAssignee = this.assigneeOptions.includes(assignee)
      ? assignee
      : this.currentAssigneeFallback();
    this.newActivity.assignee = normalizedAssignee;
    this.newActivity.professionalId = this.findProfessionalIdByName(normalizedAssignee);
    this.newGeneralPending.assignee = normalizedAssignee;
    this.newGeneralPending.professionalId = this.findProfessionalIdByName(normalizedAssignee);
    this.newFinancialEntry.assignee = normalizedAssignee;
    this.newFinancialEntry.professionalId = this.findProfessionalIdByName(normalizedAssignee);
  }

  private closeAllPanels(): void {
    this.isActivityPanelOpen = false;
    this.isPendingPanelOpen = false;
    this.isFinancePanelOpen = false;
    this.isPublicBookingModalOpen = false;
  }

  private syncPublicBookingSelection(): void {
    if (!this.isProfessionalCompatibleWithSelectedService(this.publicBookingForm.professionalId)) {
      this.publicBookingForm.professionalId = 0;
    }
  }

  private syncActivityEndTimeWithService(): void {
    const selectedService = this.selectedActivityService;

    if (!selectedService || !this.newActivity.startTime) {
      return;
    }

    this.newActivity.endTime = this.getEndTimeByDuration(
      this.newActivity.startTime,
      selectedService.durationMinutes
    );
  }

  private isProfessionalCompatibleWithSelectedService(professionalId: number): boolean {
    if (professionalId <= 0) {
      return true;
    }

    const selectedService = this.selectedPublicService;
    const professional = this.publicProfileProfessionals?.find((item) => item.id === professionalId);

    if (!selectedService?.roleId || !professional) {
      return false;
    }

    return professional.roleIds.includes(selectedService.roleId);
  }

  private getCurrentAssignee(): string {
    return this.currentAssigneeFallback();
  }

  private currentAssigneeFallback(): string {
    return this.assigneeOptions[0] ?? this.currentUser?.name ?? '';
  }

  private findProfessionalIdByName(name: string): number | null {
    const professional =
      this.companyContext?.professionals.find((existingProfessional) => existingProfessional.name === name) ??
      null;

    return professional ? professional.id : null;
  }

  private verifyEmailToken(token: string): void {
    this.isAuthLoading = true;
    this.agendaApi.verifyEmail(token).subscribe({
      next: (response) => {
        this.isAuthLoading = false;
        this.authMode = 'login';
        this.authRouteMode = 'login';
        this.authError = '';
        this.authMessage = response.message;
        this.clearVerificationTokenFromLocation('/login');
      },
      error: (error) => {
        this.isAuthLoading = false;
        this.authMode = 'login';
        this.authRouteMode = 'login';
        this.authMessage = '';
        this.authError = error?.error?.message ?? 'No fue posible verificar el correo.';
        this.clearVerificationTokenFromLocation('/login');
      }
    });
  }

  private getPublicSlugFromLocation(): string {
    const pathSegments = window.location.pathname
      .split('/')
      .map((segment) => segment.trim())
      .filter(Boolean);

    if (pathSegments.length === 0) {
      return '';
    }

    const candidate = pathSegments[pathSegments.length - 1];
    const normalizedCandidate = candidate.toLowerCase();

    if (
      normalizedCandidate === 'api' ||
      normalizedCandidate === 'login' ||
      normalizedCandidate === 'register' ||
      normalizedCandidate.includes('.') ||
      normalizedCandidate.startsWith('_karma_')
    ) {
      return '';
    }

    return decodeURIComponent(normalizedCandidate);
  }

  private getAuthRouteModeFromLocation(): 'login' | 'register' | null {
    const pathSegments = window.location.pathname
      .split('/')
      .map((segment) => segment.trim().toLowerCase())
      .filter(Boolean);
    const lastSegment = pathSegments[pathSegments.length - 1] ?? '';

    if (lastSegment === 'login') {
      return 'login';
    }

    if (lastSegment === 'register') {
      return 'register';
    }

    return null;
  }

  private getVerificationTokenFromLocation(): string {
    const searchParams = new URLSearchParams(window.location.search);
    return searchParams.get('verify_email')?.trim() ?? '';
  }

  private clearVerificationTokenFromLocation(pathname = '/'): void {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.delete('verify_email');
    currentUrl.pathname = pathname;
    window.history.replaceState({}, document.title, currentUrl.toString());
  }

  private buildEmptyActivity(): Omit<Activity, 'id'> {
    const assignee = this.currentAssigneeFallback();
    return {
      title: '',
      startTime: '',
      endTime: '',
      assignee,
      professionalId: this.findProfessionalIdByName(assignee),
      visibility: 'private',
      completed: false,
      location: '',
      description: '',
      date: this.selectedDate,
      reminderMinutes: null
    };
  }

  private buildEmptyGeneralPending(): Omit<GeneralPending, 'id'> {
    const assignee = this.currentAssigneeFallback();
    return {
      title: '',
      assignee,
      professionalId: this.findProfessionalIdByName(assignee),
      description: '',
      date: this.toIsoDate(new Date())
    };
  }

  private buildEmptyFinancialEntry(): Omit<FinancialEntry, 'id'> {
    const assignee = this.currentAssigneeFallback();
    return {
      title: '',
      type: 'income',
      amount: 0,
      assignee,
      professionalId: this.findProfessionalIdByName(assignee),
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

  private normalizePublicCalendarDate(date: Date): Date {
    return date.getDay() === 0 ? this.addDays(date, 1) : date;
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

  private buildTimeOptions(startHour: number, endHour: number): string[] {
    const times: string[] = [];

    for (let hour = startHour; hour <= endHour; hour += 1) {
      times.push(`${`${hour}`.padStart(2, '0')}:00`);

      if (hour < endHour) {
        times.push(`${`${hour}`.padStart(2, '0')}:30`);
      }
    }

    return times;
  }

  private buildWeeklyTimeOptions(startHour: number, endHour: number): string[] {
    const times: string[] = [];

    for (let hour = startHour; hour <= endHour; hour += 1) {
      times.push(`${`${hour}`.padStart(2, '0')}:00`);
    }

    return times;
  }

  private normalizeWorkingHour(value: number | null | undefined, fallback: number): number {
    const normalized = Number(value);

    if (!Number.isFinite(normalized) || normalized < 0 || normalized > 23) {
      return fallback;
    }

    return normalized;
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

  private getEndTimeByDuration(startTime: string, durationMinutes: number): string {
    const startMinutes = this.parseTimeToMinutes(startTime);

    if (!startTime || !Number.isFinite(startMinutes) || durationMinutes <= 0) {
      return '';
    }

    const totalMinutes = startMinutes + durationMinutes;
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return `${`${hours}`.padStart(2, '0')}:${`${minutes}`.padStart(2, '0')}`;
  }

  private buildActivityTitle(): string {
    const selectedService = this.selectedActivityService;
    const customerName = this.activityBookingForm.customerName.trim();
    const manualTitle = this.newActivity.title.trim();

    if (selectedService && customerName) {
      return `${selectedService.name} - ${customerName}`;
    }

    if (selectedService) {
      return selectedService.name;
    }

    if (customerName) {
      return `Cita - ${customerName}`;
    }

    return manualTitle;
  }

  private buildActivityDescription(): string {
    const metadataLines: string[] = [];
    const selectedService = this.selectedActivityService;
    const customerName = this.activityBookingForm.customerName.trim();
    const customerPhone = this.activityBookingForm.customerPhone.trim();
    const notes = this.newActivity.description.trim();

    if (selectedService) {
      metadataLines.push(`Servicio: ${selectedService.name}`);
    }

    if (customerName) {
      metadataLines.push(`Cliente: ${customerName}`);
    }

    if (customerPhone) {
      metadataLines.push(`Telefono: ${customerPhone}`);
    }

    if (notes) {
      metadataLines.push(`Notas: ${notes}`);
    }

    return metadataLines.join('\n');
  }

  private parseActivityBookingForm(activity: Activity): {
    serviceId: number;
    customerName: string;
    customerPhone: string;
    notes: string;
  } {
    const lines = activity.description
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean);
    const lineMap = new Map<string, string>();

    for (const line of lines) {
      const separatorIndex = line.indexOf(':');

      if (separatorIndex <= 0) {
        continue;
      }

      const key = line.slice(0, separatorIndex).trim().toLowerCase();
      const value = line.slice(separatorIndex + 1).trim();
      lineMap.set(key, value);
    }

    const serviceName = lineMap.get('servicio') ?? '';
    const selectedService =
      this.activeServiceOptions.find((service) => service.name.toLowerCase() === serviceName.toLowerCase()) ??
      null;

    return {
      serviceId: selectedService?.id ?? 0,
      customerName: lineMap.get('cliente') ?? '',
      customerPhone: lineMap.get('telefono') ?? '',
      notes: lineMap.get('notas') ?? activity.description
    };
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
