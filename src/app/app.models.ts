export interface Activity {
  id: number;
  title: string;
  startTime: string;
  endTime: string;
  assignee: string;
  professionalId?: number | null;
  visibility: 'private' | 'public';
  completed: boolean;
  location: string;
  description: string;
  date: string;
  reminderMinutes: number | null;
}

export interface GeneralPending {
  id: number;
  title: string;
  assignee: string;
  professionalId?: number | null;
  description: string;
  date: string;
}

export interface FinancialEntry {
  id: number;
  title: string;
  type: 'income' | 'expense';
  amount: number;
  assignee: string;
  professionalId?: number | null;
  description: string;
  date: string;
  participationPercentage: number | null;
  participantAmount: number;
}

export interface CalendarDay {
  date: Date;
  isoDate: string;
  dayNumber: number;
  inCurrentMonth: boolean;
  isToday: boolean;
  activities: Activity[];
}

export interface AuthUser {
  id: number;
  name: string;
  username: string;
  email: string;
  emailVerified: boolean;
  isSystemAdmin: boolean;
  accountType?: 'business' | 'independent';
  profilePublic: boolean;
  publicUrl: string;
  whatsappNumber: string;
  whatsappNotificationsEnabled: boolean;
  telegramChatId: string;
  telegramNotificationsEnabled: boolean;
  companyId?: number;
  companyRole?: string;
  professionalId?: number;
}

export interface AuthSession {
  authenticated: boolean;
  user: AuthUser | null;
  canRegister: boolean;
  requiresEmailVerification?: boolean;
  message?: string;
}

export interface PublicProfile {
  found: boolean;
  profileEnabled: boolean;
  workingHours?: {
    start: number;
    end: number;
  };
  user:
    | (Pick<AuthUser, 'name' | 'username' | 'publicUrl'> & {
        whatsappNumber?: string;
        whatsappContactUrl?: string;
      })
    | null;
  activities: Activity[];
  professionals?: Array<{
    id: number;
    name: string;
    roleIds: number[];
    roleNames: string[];
  }>;
  services?: CompanyService[];
}

export interface WeekGroup {
  label: string;
  activities: Activity[];
}

export interface CompanyProfile {
  id: number;
  name: string;
  slug: string;
  accountType?: 'business' | 'independent';
  status: 'active' | 'inactive' | 'suspended';
  workingHourStart: number;
  workingHourEnd: number;
}

export interface CompanySubscription {
  id: number;
  planCode: string;
  planName: string;
  status: 'active' | 'trial' | 'suspended' | 'cancelled';
  monthlyPrice: number;
  professionalLimit: number;
  startedAt: string;
  renewalDay: number | null;
}

export interface CompanyProfessional {
  id: number;
  name: string;
  email: string;
  phone: string;
  active: boolean;
  roleIds: number[];
  roleNames: string[];
  linkedUserId?: number | null;
  username?: string;
  emailVerified?: boolean;
}

export interface ServiceRole {
  id: number;
  name: string;
  active: boolean;
}

export interface CompanyService {
  id: number;
  companyId?: number;
  roleId: number | null;
  roleName: string;
  name: string;
  durationMinutes: number;
  description: string;
  active: boolean;
}

export interface CompanyStats {
  activeProfessionals: number;
  availableSlots: number;
}

export interface CompanyContext {
  company: CompanyProfile;
  subscription: CompanySubscription;
  professionals: CompanyProfessional[];
  serviceRoles: ServiceRole[];
  services: CompanyService[];
  stats: CompanyStats;
}

export interface SystemAccountSummary {
  userId: number;
  companyId: number | null;
  ownerName: string;
  ownerEmail: string;
  username: string;
  emailVerified: boolean;
  isSystemAdmin: boolean;
  companyName: string;
  companySlug: string;
  accountType: 'business' | 'independent';
  companyStatus: 'active' | 'inactive' | 'suspended';
  planName: string;
  planCode: string;
  subscriptionStatus: 'active' | 'trial' | 'suspended' | 'cancelled';
  monthlyPrice: number;
  professionalLimit: number;
  renewalDay: number | null;
  activeProfessionals: number;
  createdAt: string;
}
