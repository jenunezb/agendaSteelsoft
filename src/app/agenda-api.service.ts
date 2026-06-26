import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import {
  Activity,
  AuthSession,
  AuthUser,
  CompanyService,
  CompanyContext,
  CompanyProfessional,
  FinancialEntry,
  GeneralPending,
  PublicProfile,
  ServiceRole,
  SystemAccountSummary
} from './app.models';

type RawCompanyProfessional = Partial<CompanyProfessional> & {
  linked_user_id?: number | null;
  email_verified?: boolean | number;
  role_ids?: Array<number | string> | string;
  role_names?: string[] | string;
};

type RawServiceRole = Partial<ServiceRole>;

type RawCompanyService = Partial<CompanyService> & {
  company_id?: number;
  role_id?: number | null;
  role_name?: string | null;
  duration_minutes?: number | string;
};

type RawActivity = Partial<Activity> & {
  reminder_minutes?: number | string | null;
};

type RawAuthUser = Partial<AuthUser> & {
  email_verified?: boolean | number;
  is_system_admin?: boolean | number;
  email?: string;
  account_type?: 'business' | 'independent';
  company_id?: number;
  company_role?: string;
  professional_id?: number;
  profile_public?: boolean | number;
  public_url?: string;
  whatsapp_number?: string | null;
  whatsapp_notifications_enabled?: boolean | number;
  telegram_chat_id?: string | null;
  telegram_notifications_enabled?: boolean | number;
};

type RawAuthSession = Omit<AuthSession, 'user'> & {
  user: RawAuthUser | null;
};

type RawFinancialEntry = Partial<FinancialEntry> & {
  entry_type?: FinancialEntry['type'];
  entry_date?: string;
  participation_percentage?: number | string | null;
  participant_amount?: number | string | null;
};

@Injectable({
  providedIn: 'root'
})
export class AgendaApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = '/api';

  getActivities(): Observable<Activity[]> {
    return this.http
      .get<RawActivity[]>(`${this.baseUrl}/activities.php`)
      .pipe(map((activities) => activities.map((activity) => this.normalizeActivity(activity))));
  }

  getSessionStatus(): Observable<AuthSession> {
    return this.http
      .get<RawAuthSession>(`${this.baseUrl}/auth.php`)
      .pipe(map((session) => this.normalizeAuthSession(session)));
  }

  getPublicProfile(username: string): Observable<PublicProfile> {
    return this.http
      .get<PublicProfile>(`${this.baseUrl}/public-profile.php?username=${encodeURIComponent(username)}`)
      .pipe(
        map((profile) => ({
          ...profile,
          activities: (profile.activities ?? []).map((activity) => this.normalizeActivity(activity)),
          professionals: (profile.professionals ?? []).map((professional) => ({
            id: Number(professional.id) || 0,
            name: professional.name?.trim() ?? '',
            roleIds: Array.isArray(professional.roleIds) ? professional.roleIds.map((roleId) => Number(roleId) || 0).filter((roleId) => roleId > 0) : [],
            roleNames: Array.isArray(professional.roleNames) ? professional.roleNames.map((roleName) => roleName?.trim() ?? '').filter(Boolean) : []
          })),
          services: (profile.services ?? []).map((service) =>
            this.normalizeCompanyService(service as RawCompanyService)
          )
        }))
      );
  }

  login(username: string, password: string): Observable<AuthSession> {
    return this.http
      .post<RawAuthSession>(`${this.baseUrl}/auth.php`, {
        action: 'login',
        username,
        password
      })
      .pipe(map((session) => this.normalizeAuthSession(session)));
  }

  register(name: string, username: string, password: string): Observable<AuthSession> {
    return this.http
      .post<RawAuthSession>(`${this.baseUrl}/auth.php`, {
        action: 'register',
        name,
        username,
        password
      })
      .pipe(map((session) => this.normalizeAuthSession(session)));
  }

  registerAccount(payload: {
    name: string;
    username: string;
    email: string;
    password: string;
    companyName: string;
    accountType: 'business' | 'independent';
  }): Observable<AuthSession> {
    return this.http
      .post<RawAuthSession>(`${this.baseUrl}/auth.php`, {
        action: 'register',
        ...payload
      })
      .pipe(map((session) => this.normalizeAuthSession(session)));
  }

  logout(): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/auth.php`);
  }

  updateProfileVisibility(profilePublic: boolean): Observable<AuthSession> {
    return this.http
      .put<RawAuthSession>(`${this.baseUrl}/auth.php`, {
        action: 'updateProfile',
        profilePublic
      })
      .pipe(map((session) => this.normalizeAuthSession(session)));
  }

  updateNotificationSettings(
    whatsappNumber: string,
    whatsappNotificationsEnabled: boolean,
    telegramChatId: string,
    telegramNotificationsEnabled: boolean
  ): Observable<AuthSession> {
    return this.http
      .put<RawAuthSession>(`${this.baseUrl}/auth.php`, {
        action: 'updateNotifications',
        whatsappNumber,
        whatsappNotificationsEnabled,
        telegramChatId,
        telegramNotificationsEnabled
      })
      .pipe(map((session) => this.normalizeAuthSession(session)));
  }

  createActivity(activity: Omit<Activity, 'id'>): Observable<Activity> {
    return this.http
      .post<RawActivity>(`${this.baseUrl}/activities.php`, activity)
      .pipe(map((savedActivity) => this.normalizeActivity(savedActivity)));
  }

  updateActivity(id: number, activity: Omit<Activity, 'id'>): Observable<Activity> {
    return this.http
      .put<RawActivity>(`${this.baseUrl}/activities.php?id=${id}`, activity)
      .pipe(map((savedActivity) => this.normalizeActivity(savedActivity)));
  }

  deleteActivity(id: number): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/activities.php?id=${id}`);
  }

  getGeneralPendings(): Observable<GeneralPending[]> {
    return this.http.get<GeneralPending[]>(`${this.baseUrl}/pendings.php`);
  }

  createGeneralPending(pending: Omit<GeneralPending, 'id'>): Observable<GeneralPending> {
    return this.http.post<GeneralPending>(`${this.baseUrl}/pendings.php`, pending);
  }

  updateGeneralPending(
    id: number,
    pending: Omit<GeneralPending, 'id'>
  ): Observable<GeneralPending> {
    return this.http.put<GeneralPending>(`${this.baseUrl}/pendings.php?id=${id}`, pending);
  }

  deleteGeneralPending(id: number): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/pendings.php?id=${id}`);
  }

  getFinancialEntries(): Observable<FinancialEntry[]> {
    return this.http
      .get<RawFinancialEntry[]>(`${this.baseUrl}/financial-entries.php`)
      .pipe(map((entries) => entries.map((entry) => this.normalizeFinancialEntry(entry))));
  }

  createFinancialEntry(entry: Omit<FinancialEntry, 'id'>): Observable<FinancialEntry> {
    return this.http
      .post<RawFinancialEntry>(`${this.baseUrl}/financial-entries.php`, entry)
      .pipe(map((savedEntry) => this.normalizeFinancialEntry(savedEntry)));
  }

  updateFinancialEntry(
    id: number,
    entry: Omit<FinancialEntry, 'id'>
  ): Observable<FinancialEntry> {
    return this.http
      .put<RawFinancialEntry>(`${this.baseUrl}/financial-entries.php?id=${id}`, entry)
      .pipe(map((savedEntry) => this.normalizeFinancialEntry(savedEntry)));
  }

  deleteFinancialEntry(id: number): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/financial-entries.php?id=${id}`);
  }

  getCompanyContext(): Observable<CompanyContext> {
    return this.http.get<CompanyContext>(`${this.baseUrl}/company.php`);
  }

  updateCompany(
    company: Pick<CompanyContext['company'], 'name' | 'workingHourStart' | 'workingHourEnd'>
  ): Observable<CompanyContext> {
    return this.http.put<CompanyContext>(`${this.baseUrl}/company.php`, {
      action: 'updateCompany',
      ...company
    });
  }

  updateSubscription(
    subscription: Pick<
      CompanyContext['subscription'],
      'planName' | 'planCode' | 'status' | 'monthlyPrice' | 'professionalLimit' | 'renewalDay'
    >
  ): Observable<CompanyContext> {
    return this.http.put<CompanyContext>(`${this.baseUrl}/company.php`, {
      action: 'updateSubscription',
      ...subscription
    });
  }

  createProfessional(
    professional: Pick<CompanyProfessional, 'name' | 'email' | 'phone' | 'active' | 'roleIds'>
  ): Observable<CompanyProfessional> {
    return this.http
      .post<RawCompanyProfessional>(`${this.baseUrl}/professionals.php`, professional)
      .pipe(map((savedProfessional) => this.normalizeCompanyProfessional(savedProfessional)));
  }

  updateProfessional(
    id: number,
    professional: Pick<CompanyProfessional, 'name' | 'email' | 'phone' | 'active' | 'roleIds'>
  ): Observable<CompanyProfessional> {
    return this.http
      .put<RawCompanyProfessional>(`${this.baseUrl}/professionals.php?id=${id}`, professional)
      .pipe(map((savedProfessional) => this.normalizeCompanyProfessional(savedProfessional)));
  }

  deleteProfessional(id: number): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/professionals.php?id=${id}`);
  }

  getServiceRoles(): Observable<ServiceRole[]> {
    return this.http
      .get<RawServiceRole[]>(`${this.baseUrl}/service-roles.php`)
      .pipe(map((roles) => roles.map((role) => this.normalizeServiceRole(role))));
  }

  createServiceRole(role: Omit<ServiceRole, 'id'>): Observable<ServiceRole> {
    return this.http
      .post<RawServiceRole>(`${this.baseUrl}/service-roles.php`, role)
      .pipe(map((savedRole) => this.normalizeServiceRole(savedRole)));
  }

  updateServiceRole(id: number, role: Omit<ServiceRole, 'id'>): Observable<ServiceRole> {
    return this.http
      .put<RawServiceRole>(`${this.baseUrl}/service-roles.php?id=${id}`, role)
      .pipe(map((savedRole) => this.normalizeServiceRole(savedRole)));
  }

  deleteServiceRole(id: number): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/service-roles.php?id=${id}`);
  }

  getServices(): Observable<CompanyService[]> {
    return this.http
      .get<RawCompanyService[]>(`${this.baseUrl}/services.php`)
      .pipe(map((services) => services.map((service) => this.normalizeCompanyService(service))));
  }

  createService(service: Omit<CompanyService, 'id' | 'companyId' | 'roleName'>): Observable<CompanyService> {
    return this.http
      .post<RawCompanyService>(`${this.baseUrl}/services.php`, service)
      .pipe(map((savedService) => this.normalizeCompanyService(savedService)));
  }

  updateService(
    id: number,
    service: Omit<CompanyService, 'id' | 'companyId' | 'roleName'>
  ): Observable<CompanyService> {
    return this.http
      .put<RawCompanyService>(`${this.baseUrl}/services.php?id=${id}`, service)
      .pipe(map((savedService) => this.normalizeCompanyService(savedService)));
  }

  deleteService(id: number): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/services.php?id=${id}`);
  }

  verifyEmail(token: string): Observable<{ success: boolean; message: string }> {
    return this.http.post<{ success: boolean; message: string }>(`${this.baseUrl}/auth.php`, {
      action: 'verifyEmail',
      token
    });
  }

  getSystemAccounts(): Observable<SystemAccountSummary[]> {
    return this.http.get<SystemAccountSummary[]>(`${this.baseUrl}/admin.php`);
  }

  updateSystemAccount(payload: {
    companyId: number;
    companyStatus: 'active' | 'inactive' | 'suspended';
    planName: string;
    planCode: string;
    subscriptionStatus: 'active' | 'trial' | 'suspended' | 'cancelled';
    monthlyPrice: number;
    professionalLimit: number;
    renewalDay?: number | null;
  }): Observable<SystemAccountSummary[]> {
    return this.http.put<SystemAccountSummary[]>(`${this.baseUrl}/admin.php`, payload);
  }

  createPublicBooking(payload: {
    username: string;
    serviceId: number;
    professionalId: number | null;
    customerName: string;
    customerPhone: string;
    date: string;
    startTime: string;
    notes: string;
  }): Observable<{ success: boolean; message: string }> {
    return this.http.post<{ success: boolean; message: string }>(
      `${this.baseUrl}/public-bookings.php`,
      payload
    );
  }

  private normalizeApiDate(rawDate: string | null | undefined): string {
    if (typeof rawDate !== 'string') {
      return '';
    }

    const trimmedDate = rawDate.trim();

    if (!trimmedDate) {
      return '';
    }

    const isoMatch = trimmedDate.match(/^(\d{4}-\d{2}-\d{2})/);
    if (isoMatch) {
      return isoMatch[1];
    }

    const localMatch = trimmedDate.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (localMatch) {
      const [, day, month, year] = localMatch;
      return `${year}-${month}-${day}`;
    }

    return '';
  }

  private normalizeFinancialEntry(entry: RawFinancialEntry): FinancialEntry {
    return {
      id: Number(entry.id) || 0,
      title: entry.title?.trim() ?? '',
      type: entry.type === 'expense' || entry.entry_type === 'expense' ? 'expense' : 'income',
      amount: Number(entry.amount) || 0,
      assignee: entry.assignee?.trim() ?? '',
      professionalId: entry.professionalId ? Number(entry.professionalId) : null,
      description: entry.description ?? '',
      date: this.normalizeApiDate(entry.date ?? entry.entry_date),
      participationPercentage: this.normalizeParticipationPercentage(
        entry.participationPercentage ?? entry.participation_percentage
      ),
      participantAmount: Number(entry.participantAmount ?? entry.participant_amount) || 0
    };
  }

  private normalizeParticipationPercentage(
    value: number | string | null | undefined
  ): number | null {
    if (value === null || value === undefined || value === '') {
      return null;
    }

    const normalizedValue = Number(value);
    if (!Number.isFinite(normalizedValue)) {
      return null;
    }

    return normalizedValue >= 0 && normalizedValue <= 100 ? normalizedValue : null;
  }

  private normalizeReminderMinutes(value: number | string | null | undefined): number | null {
    if (value === null || value === undefined || value === '') {
      return null;
    }

    const normalizedValue = Number(value);
    if (!Number.isFinite(normalizedValue)) {
      return null;
    }

    return normalizedValue >= 1 && normalizedValue <= 1440 ? normalizedValue : null;
  }

  private normalizeActivity(activity: RawActivity): Activity {
    return {
      id: Number(activity.id) || 0,
      title: activity.title?.trim() ?? '',
      startTime: activity.startTime?.trim() ?? '',
      endTime: activity.endTime?.trim() ?? '',
      assignee: activity.assignee?.trim() ?? '',
      professionalId: activity.professionalId ? Number(activity.professionalId) : null,
      visibility: activity.visibility === 'public' ? 'public' : 'private',
      completed: Boolean(activity.completed),
      location: activity.location?.trim() ?? '',
      description: activity.description ?? '',
      date: this.normalizeApiDate(activity.date),
      reminderMinutes: this.normalizeReminderMinutes(
        activity.reminderMinutes ?? activity.reminder_minutes
      )
    };
  }

  private normalizeAuthSession(session: RawAuthSession): AuthSession {
    return {
      authenticated: Boolean(session.authenticated),
      canRegister: Boolean(session.canRegister),
      user: session.user ? this.normalizeAuthUser(session.user) : null,
      requiresEmailVerification: Boolean(session.requiresEmailVerification),
      message: typeof session.message === 'string' ? session.message : ''
    };
  }

  private normalizeAuthUser(user: RawAuthUser): AuthUser {
    return {
      id: Number(user.id) || 0,
      name: user.name?.trim() ?? '',
      username: user.username?.trim() ?? '',
      email: user.email?.trim() ?? '',
      emailVerified: Boolean(user.emailVerified ?? user.email_verified),
      isSystemAdmin: Boolean(user.isSystemAdmin ?? user.is_system_admin),
      accountType: user.accountType ?? user.account_type ?? 'business',
      profilePublic: Boolean(user.profilePublic ?? user.profile_public),
      publicUrl: user.publicUrl?.trim() ?? user.public_url?.trim() ?? '',
      whatsappNumber: user.whatsappNumber?.trim() ?? user.whatsapp_number?.trim() ?? '',
      whatsappNotificationsEnabled: Boolean(
        user.whatsappNotificationsEnabled ?? user.whatsapp_notifications_enabled
      ),
      telegramChatId: user.telegramChatId?.trim() ?? user.telegram_chat_id?.trim() ?? '',
      telegramNotificationsEnabled: Boolean(
        user.telegramNotificationsEnabled ?? user.telegram_notifications_enabled
      ),
      companyId: Number(user.companyId ?? user.company_id) || 0,
      companyRole: user.companyRole?.trim() ?? user.company_role?.trim() ?? '',
      professionalId: Number(user.professionalId ?? (user as RawAuthUser & { professional_id?: number }).professional_id) || 0
    };
  }

  private normalizeCompanyProfessional(professional: RawCompanyProfessional): CompanyProfessional {
    return {
      id: Number(professional.id) || 0,
      name: professional.name?.trim() ?? '',
      email: professional.email?.trim() ?? '',
      phone: professional.phone?.trim() ?? '',
      active: Boolean(professional.active),
      roleIds: this.normalizeNumericList(professional.roleIds ?? professional.role_ids),
      roleNames: this.normalizeStringList(professional.roleNames ?? professional.role_names),
      linkedUserId: professional.linkedUserId
        ? Number(professional.linkedUserId)
        : professional.linked_user_id
          ? Number(professional.linked_user_id)
          : null,
      username: professional.username?.trim() ?? '',
      emailVerified: Boolean(professional.emailVerified ?? professional.email_verified)
    };
  }

  private normalizeServiceRole(role: RawServiceRole): ServiceRole {
    return {
      id: Number(role.id) || 0,
      name: role.name?.trim() ?? '',
      active: Boolean(role.active)
    };
  }

  private normalizeCompanyService(service: RawCompanyService): CompanyService {
    return {
      id: Number(service.id) || 0,
      companyId: Number(service.companyId ?? service.company_id) || 0,
      roleId:
        service.roleId !== undefined && service.roleId !== null
          ? Number(service.roleId)
          : service.role_id !== undefined && service.role_id !== null
            ? Number(service.role_id)
            : null,
      roleName: service.roleName?.trim() ?? service.role_name?.trim() ?? '',
      name: service.name?.trim() ?? '',
      durationMinutes: Number(service.durationMinutes ?? service.duration_minutes) || 0,
      description: service.description?.trim() ?? '',
      active: Boolean(service.active)
    };
  }

  private normalizeNumericList(value: unknown): number[] {
    if (Array.isArray(value)) {
      return value.map((item) => Number(item) || 0).filter((item) => item > 0);
    }

    if (typeof value === 'string') {
      return value
        .split(',')
        .map((item) => Number(item.trim()) || 0)
        .filter((item) => item > 0);
    }

    return [];
  }

  private normalizeStringList(value: unknown): string[] {
    if (Array.isArray(value)) {
      return value.map((item) => `${item ?? ''}`.trim()).filter(Boolean);
    }

    if (typeof value === 'string') {
      return value
        .split('||')
        .map((item) => item.trim())
        .filter(Boolean);
    }

    return [];
  }
}
